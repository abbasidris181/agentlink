<?php

namespace Tests\Feature;

use App\Models\ServicePrice;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ManualRequestConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_overlapping_service_submissions_charge_once_without_overdrawing_the_wallet(bool $sameRequest): void
    {
        $connection = config('database.connections.'.config('database.default'));
        $this->assertSame('mysql', $connection['driver']);
        $this->assertStringContainsString('test', $connection['database']);
        $sender = User::factory()->agent()->create(['wallet_balance' => 10000]);
        ServicePrice::factory()->create(['service' => 'validations', 'method' => 'nin', 'amount' => 6000]);
        $key = (string) Str::uuid();
        $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'wallet_test', 'database.connections.wallet_test' => json_decode(getenv('WALLET_TEST_CONNECTION'), true)]);
\Illuminate\Support\Facades\DB::purge('wallet_test');
fwrite(STDOUT, "READY\n");
try {
    $sender = \App\Models\User::query()->findOrFail((int) $argv[1]);
    app(\App\SubmitServiceRequest::class)->create($sender, 'validations', 'nin', ['nin' => '01234567890'], $argv[2]);
    fwrite(STDOUT, "COMPLETED\n");
} catch (\Illuminate\Validation\ValidationException $exception) {
    fwrite(STDOUT, "REJECTED\n");
}
PHP;
        $workers = [];
        DB::beginTransaction();
        try {
            User::query()->whereKey($sender->id)->lockForUpdate()->firstOrFail();
            foreach ([$key, $sameRequest ? $key : (string) Str::uuid()] as $requestKey) {
                $process = new Process([PHP_BINARY, '-r', $script, (string) $sender->id, $requestKey], base_path(),
                    ['APP_ENV' => 'testing', 'WALLET_TEST_CONNECTION' => json_encode($connection)], timeout: 15);
                $process->start();
                $workers[] = $process;
            }
            $deadline = microtime(true) + 8;
            do {
                $ready = count(array_filter($workers, fn (Process $worker): bool => str_contains($worker->getOutput(), 'READY')));
                if ($ready === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $ready, 'Both submission workers must start before releasing the wallet lock.');
        } finally {
            DB::rollBack();
        }
        $completed = 0;
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $completed += str_contains($worker->getOutput(), 'COMPLETED') ? 1 : 0;
        }
        $this->assertSame($sameRequest ? 2 : 1, $completed);
        $this->assertSame(4000, $sender->fresh()->wallet_balance);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertSame(1, WalletTransaction::query()->count());
        $this->assertDatabaseCount('admin_audits', 1);
    }
}
