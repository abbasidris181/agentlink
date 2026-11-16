<?php

namespace Tests\Feature;

use App\Models\MonnifyAccount;
use App\Models\MonnifyPayment;
use App\Models\User;
use App\MonnifySettings;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MonnifyConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_overlapping_generation_creates_one_account_and_sends_one_reservation_post(): void
    {
        MonnifySettings::save($this->settings());
        $owner = User::factory()->create(['wallet_balance' => 50000]);
        $outputs = $this->workers($owner->id, [['reserve', $owner->id], ['reserve', $owner->id]]);
        $this->assertSame(1, substr_count(implode('', $outputs), 'RESERVE_POST'));
        $this->assertSame('active', MonnifyAccount::query()->sole()->status);
        $this->assertSame(50000, $owner->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_overlapping_payment_workers_credit_the_balance_exactly_once(): void
    {
        MonnifySettings::save($this->settings());
        $owner = User::factory()->create(['wallet_balance' => 50000]);
        $account = MonnifyAccount::factory()->create(['user_id' => $owner->id]);
        $body = [
            'transactionReference' => 'MNFY|CONCURRENT|001', 'paymentReference' => 'PAY-001',
            'paymentStatus' => 'PAID', 'currency' => 'NGN', 'paymentMethod' => 'ACCOUNT_TRANSFER',
            'product' => ['type' => 'RESERVED_ACCOUNT', 'reference' => $account->reference],
            'amountPaid' => '1000.00', 'settlementAmount' => '990.00',
        ];
        $payment = MonnifyPayment::factory()->create([
            'monnify_account_id' => $account->id, 'transaction_reference' => $body['transactionReference'],
            'payment_reference' => $body['paymentReference'], 'event_data' => $body, 'charge_basis_points' => 200,
        ]);
        $this->workers($owner->id, [['verify', $payment->id], ['verify', $payment->id]], $body);
        $this->assertSame('credited', $payment->fresh()->status);
        $this->assertSame(148000, $owner->fresh()->wallet_balance);
        $this->assertSame(98000, $payment->fresh()->credited_amount);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    /**
     * @param  list<array{string, int}>  $arguments
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function workers(int $userId, array $arguments, array $body = []): array
    {
        $connection = config('database.connections.'.config('database.default'));
        $this->assertSame('mysql', $connection['driver']);
        $this->assertStringContainsString('test', $connection['database']);
        $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'monnify_test', 'database.connections.monnify_test' => json_decode(getenv('MONNIFY_TEST_CONNECTION'), true)]);
\Illuminate\Support\Facades\DB::purge('monnify_test');
\Illuminate\Support\Facades\Http::preventStrayRequests();
$envelope = fn (array $body): array => ['requestSuccessful' => true, 'responseCode' => '0', 'responseMessage' => 'success', 'responseBody' => $body];
\Illuminate\Support\Facades\Http::fake([
    'https://api.monnify.com/api/v1/auth/login' => \Illuminate\Support\Facades\Http::response($envelope(['accessToken' => 'fake-token'])),
    'https://api.monnify.com/api/v2/merchant/transactions/query*' => \Illuminate\Support\Facades\Http::response($envelope(json_decode(getenv('MONNIFY_TEST_BODY'), true))),
    'https://api.monnify.com/api/v2/bank-transfer/reserved-accounts' => function ($request) use ($envelope) {
        fwrite(STDOUT, "RESERVE_POST\n");
        return \Illuminate\Support\Facades\Http::response($envelope([
            'accountReference' => $request['accountReference'], 'accountName' => $request['accountName'], 'contractCode' => $request['contractCode'],
            'customerEmail' => $request['customerEmail'], 'currencyCode' => 'NGN', 'collectionChannel' => 'RESERVED_ACCOUNT',
            'reservedAccountType' => 'GENERAL', 'reservationReference' => 'CONCURRENT-RESERVATION', 'status' => 'ACTIVE',
            'accounts' => [['bankCode' => '50515', 'bankName' => 'Moniepoint', 'accountNumber' => '6254727989']],
        ]));
    },
]);
fwrite(STDOUT, "READY\n");
if ($argv[1] === 'reserve') {
    app(\App\MonnifyFundingService::class)->reserve(\App\Models\User::query()->findOrFail((int) $argv[2]), '', '01234567890', true);
} else {
    app(\App\MonnifyFundingService::class)->verifyPayment((int) $argv[2]);
}
fwrite(STDOUT, "COMPLETED\n");
PHP;
        $workers = [];
        DB::beginTransaction();
        try {
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            foreach ($arguments as $values) {
                $worker = new Process([PHP_BINARY, '-r', $script, ...array_map('strval', $values)], base_path(), [
                    'APP_ENV' => 'testing', 'MONNIFY_TEST_CONNECTION' => json_encode($connection), 'MONNIFY_TEST_BODY' => json_encode($body),
                ], timeout: 25);
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
        $outputs = [];
        foreach ($workers as $worker) {
            $worker->wait();
            $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            $outputs[] = $worker->getOutput();
        }

        return $outputs;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ['enabled' => true, 'mode' => 'live', 'api_key' => 'fake-api-key', 'secret_key' => 'fake-secret', 'contract_code' => '1234567890', 'funding_charge' => '2.00'];
    }
}
