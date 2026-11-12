<?php

namespace Tests\Feature;

use App\InstantIpeService;
use App\Models\ServicePrice;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InstantIpeConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_overlapping_submissions_preserve_balance_and_idempotency(bool $sameRequest): void
    {
        $owner = User::factory()->create(['wallet_balance' => 10000]);
        ServicePrice::factory()->create(['service' => 'instant-ipe', 'method' => 'single', 'amount' => 6000]);
        $key = (string) Str::uuid();
        $workers = $this->workers($owner->id, [
            ['submit', (string) $owner->id, 'ABC123DEF456GH7', $key],
            ['submit', (string) $owner->id, $sameRequest ? 'ABC123DEF456GH7' : 'XYZ987UVW654ST3', $sameRequest ? $key : (string) Str::uuid()],
        ]);
        $this->assertSame($sameRequest ? 2 : 1, count(array_filter($workers, fn (string $output): bool => str_contains($output, 'COMPLETED'))));
        $this->assertSame(4000, $owner->fresh()->wallet_balance);
        $this->assertDatabaseCount('instant_ipe_requests', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_overlapping_failure_checks_refund_only_the_original_charge_once(): void
    {
        config(['services.tracking_id.tracking_url' => 'https://ipe.example.test', 'services.tracking_id.tracking_token' => 'test-token']);
        $owner = User::factory()->create(['wallet_balance' => 10000]);
        ServicePrice::factory()->create(['service' => 'instant-ipe', 'method' => 'single', 'amount' => 6000]);
        $record = app(InstantIpeService::class)->submit($owner, 'single', ['ABC123DEF456GH7'], (string) Str::uuid())->requests->sole();
        $record->status = 'pending';
        $record->dispatch_started_at = now()->subMinutes(2);
        $record->save();
        $this->workers($owner->id, [['check', (string) $record->id], ['check', (string) $record->id]]);
        $this->assertSame(10000, $owner->fresh()->wallet_balance);
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertNotNull($record->fresh()->refunded_at);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(1, WalletTransaction::query()->whereNotNull('refund_of_id')->count());
        $this->assertDatabaseCount('admin_audits', 2);
    }

    /** @param list<list<string>> $arguments @return list<string> */
    private function workers(int $userId, array $arguments): array
    {
        $connection = config('database.connections.'.config('database.default'));
        $this->assertSame('mysql', $connection['driver']);
        $this->assertStringContainsString('test', $connection['database']);
        $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'instant_test', 'database.connections.instant_test' => json_decode(getenv('INSTANT_TEST_CONNECTION'), true), 'services.tracking_id.tracking_url' => 'https://ipe.example.test', 'services.tracking_id.tracking_token' => 'test-token']);
\Illuminate\Support\Facades\DB::purge('instant_test');
fwrite(STDOUT, "READY\n");
try {
    if ($argv[1] === 'submit') {
        $owner = \App\Models\User::query()->findOrFail((int) $argv[2]);
        app(\App\InstantIpeService::class)->submit($owner, 'single', [$argv[3]], $argv[4]);
    } else {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Http::fake(fn () => \Illuminate\Support\Facades\Http::response(['status' => 'Failed', 'reply' => 'Ready for Abis', 'nin' => null]));
        app(\App\InstantIpeService::class)->checkRequest((int) $argv[2]);
    }
    fwrite(STDOUT, "COMPLETED\n");
} catch (\Illuminate\Validation\ValidationException) {
    fwrite(STDOUT, "REJECTED\n");
}
PHP;
        $workers = [];
        DB::beginTransaction();
        try {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            foreach ($arguments as $values) {
                $worker = new Process([PHP_BINARY, '-r', $script, ...$values], base_path(), ['APP_ENV' => 'testing', 'INSTANT_TEST_CONNECTION' => json_encode($connection)], timeout: 20);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 8;
            do {
                $ready = count(array_filter($workers, fn (Process $worker): bool => str_contains($worker->getOutput(), 'READY')));
                if ($ready === count($workers)) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(count($workers), $ready);
        } finally {
            DB::rollBack();
        }
        $output = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $output[] = $worker->getOutput();
        }

        return $output;
    }
}
