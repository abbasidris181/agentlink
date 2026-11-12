<?php

namespace Tests\Feature;

use App\Models\ServicePrice;
use App\Models\User;
use App\Models\WalletTransaction;
use App\SubmitServiceRequest;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ServiceRequestRefundConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_overlapping_refunds_credit_the_wallet_only_once(): void
    {
        $connection = config('database.connections.'.config('database.default'));
        $this->assertSame('mysql', $connection['driver']);
        $this->assertStringContainsString('test', $connection['database']);
        $sender = User::factory()->agent()->create(['wallet_balance' => 10000]);
        ServicePrice::factory()->create(['service' => 'validations', 'method' => 'nin', 'amount' => 6000]);
        $record = app(SubmitServiceRequest::class)->create($sender, 'validations', 'nin', ['nin' => '01234567890'], (string) Str::uuid());
        $admin = User::factory()->administrator()->create();
        $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'wallet_test', 'database.connections.wallet_test' => json_decode(getenv('WALLET_TEST_CONNECTION'), true)]);
\Illuminate\Support\Facades\DB::purge('wallet_test');
fwrite(STDOUT, "READY\n");
try {
    $sender = \App\Models\User::query()->findOrFail((int) $argv[1]);
    app(\App\RefundServiceRequest::class)->refund($sender, (int) $argv[2]);
    fwrite(STDOUT, "COMPLETED\n");
} catch (\Illuminate\Validation\ValidationException $exception) {
    fwrite(STDOUT, "REJECTED\n");
}
PHP;
        $workers = [];
        DB::beginTransaction();
        try {
            User::query()->whereKey($sender->id)->lockForUpdate()->firstOrFail();
            foreach ([1, 2] as $workerNumber) {
                $process = new Process([PHP_BINARY, '-r', $script, (string) $admin->id, (string) $record->id], base_path(),
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
            $this->assertSame(2, $ready, 'Both refund workers must start before releasing the wallet lock.');
        } finally {
            DB::rollBack();
        }
        $completed = 0;
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $completed += str_contains($worker->getOutput(), 'COMPLETED') ? 1 : 0;
        }
        $this->assertSame(2, $completed);
        $this->assertSame(10000, $sender->fresh()->wallet_balance);
        $this->assertSame('refunded', $record->fresh()->status);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertSame(2, WalletTransaction::query()->count());
        $this->assertSame(1, WalletTransaction::query()->whereNotNull('refund_of_id')->count());
        $this->assertDatabaseCount('admin_audits', 2);
    }
}
