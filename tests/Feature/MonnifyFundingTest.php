<?php

namespace Tests\Feature;

use App\Jobs\VerifyMonnifyPayment;
use App\Livewire\Admin\MonnifyConfiguration;
use App\Livewire\WalletFunding;
use App\Models\AdminAudit;
use App\Models\MonnifyAccount;
use App\Models\MonnifyPayment;
use App\Models\SiteSetting;
use App\Models\User;
use App\Money;
use App\MonnifyFundingService;
use App\MonnifySettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class MonnifyFundingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        MonnifySettings::save($this->settings());
    }

    public function test_admin_credentials_and_funding_percentage_are_saved_in_the_database_without_exposing_existing_keys(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $component = Livewire::test(MonnifyConfiguration::class)
            ->assertSet('apiKey', '')->assertSet('secretKey', '')
            ->assertDontSee('test-secret-key')->assertDontSee('test-api-key')
            ->set('apiKey', 'new-api-key')->set('secretKey', 'new-secret-key')->set('fundingCharge', '2.50')
            ->call('save')->assertHasNoErrors()->assertSet('apiKey', '')->assertSet('secretKey', '');
        $raw = SiteSetting::query()->where('key', 'monnify')->value('value');
        $this->assertSame('new-api-key', json_decode($raw, true)['api_key']);
        $this->assertSame('new-secret-key', json_decode($raw, true)['secret_key']);
        $component->set('fundingCharge', '3.25')->call('save')->assertHasNoErrors();
        $this->assertSame('new-secret-key', MonnifySettings::get()['secret_key']);
        $this->assertSame('3.25', MonnifySettings::get()['funding_charge']);
        $this->assertStringNotContainsString('new-secret-key', json_encode(AdminAudit::query()->get()->toArray()));
        Http::assertNothingSent();
    }

    public function test_only_authorized_admins_can_view_or_change_payment_settings(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(MonnifyConfiguration::class)->assertForbidden();
        $this->actingAs(User::factory()->staff(['settings.view'])->create());
        Livewire::test(MonnifyConfiguration::class)->assertDontSee('test-secret-key')->call('save')->assertForbidden();
        $this->actingAs(User::factory()->staff(['dashboard.view'])->create());
        Livewire::test(MonnifyConfiguration::class)->assertForbidden();
        Http::assertNothingSent();
    }

    #[TestWith(['-1'])]
    #[TestWith(['100.01'])]
    #[TestWith(['1.234'])]
    #[TestWith(['abc'])]
    #[TestWith([''])]
    public function test_invalid_percentage_charges_are_rejected(string $percentage): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(MonnifyConfiguration::class)->set('fundingCharge', $percentage)->call('save')->assertHasErrors(['funding_charge']);
        $this->assertSame('2.00', MonnifySettings::get()['funding_charge']);
    }

    public function test_contract_or_environment_cannot_change_while_live_reserved_accounts_exist(): void
    {
        MonnifyAccount::factory()->create();
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(MonnifyConfiguration::class)->set('mode', 'sandbox')->call('save')->assertHasErrors(['contract_code'])
            ->set('mode', 'live')->set('contractCode', '999999')->call('save')->assertHasErrors(['contract_code']);
        $this->assertSame('1234567890', MonnifySettings::get()['contract_code']);
    }

    #[TestWith(['22484232222', ''])]
    #[TestWith(['', '01234567890'])]
    #[TestWith(['22484232222', '01234567890'])]
    public function test_customers_create_one_static_account_with_the_documented_contract(string $bvn, string $nin): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->fakeCreation();
        $component = Livewire::test(WalletFunding::class)->set('bvn', $bvn)->set('nin', $nin)->set('consent', true)
            ->call('createAccount')->assertHasNoErrors()->assertSee('6254727989')->assertSee('Moniepoint Microfinance Bank')
            ->assertSet('bvn', '')->assertSet('nin', '');
        $account = MonnifyAccount::query()->sole();
        $this->assertSame($user->id, $account->user_id);
        $this->assertSame('active', $account->status);
        app(MonnifyFundingService::class)->reserve($user, $bvn, $nin, true);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monnify.com/api/v1/auth/login'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-api-key:test-secret-key')));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.monnify.com/api/v2/bank-transfer/reserved-accounts'
            && $request->hasHeader('Authorization', 'Bearer test-access-token')
            && $request['accountReference'] === $account->reference && $request['accountName'] === $user->name
            && $request['customerEmail'] === $user->email && $request['contractCode'] === '1234567890'
            && $request['currencyCode'] === 'NGN' && $request['getAllAvailableBanks'] === true
            && ($request['bvn'] ?? '') === $bvn && ($request['nin'] ?? '') === $nin);
        $this->assertDatabaseCount('monnify_accounts', 1);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $component->assertDontSee('test-secret-key')->assertDontSee('test-api-key')->assertDontSee('test-access-token');
    }

    public function test_identity_and_consent_are_required_without_creating_accounts_or_calling_the_provider(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(WalletFunding::class)->call('createAccount')->assertHasErrors(['bvn', 'nin', 'consent'])
            ->set('bvn', 'short')->set('consent', true)->call('createAccount')->assertHasErrors(['bvn']);
        $this->assertDatabaseCount('monnify_accounts', 0);
        Http::assertNothingSent();
    }

    public function test_missing_credentials_and_disabled_account_creation_prevent_outbound_requests(): void
    {
        $this->actingAs(User::factory()->create());
        MonnifySettings::save([...$this->settings(), 'enabled' => false]);
        Livewire::test(WalletFunding::class)->assertSee('Wallet funding is not available yet')
            ->set('nin', '01234567890')->set('consent', true)->call('createAccount')->assertHasErrors(['funding']);
        Http::assertNothingSent();
    }

    public function test_timed_out_creation_is_recovered_using_get_without_reposting_or_changing_reference(): void
    {
        $user = User::factory()->create();
        $attempts = 0;
        Http::fake([
            'https://api.monnify.com/api/v1/auth/login' => Http::response($this->envelope(['accessToken' => 'test-access-token', 'expiresIn' => 3600])),
            'https://api.monnify.com/api/v2/bank-transfer/reserved-accounts' => function () use (&$attempts) {
                $attempts++;
                throw new ConnectionException('Interrupted');
            },
            'https://api.monnify.com/api/v2/bank-transfer/reserved-accounts/*' => fn () => Http::response($this->envelope($this->accountBody(MonnifyAccount::query()->sole()))),
        ]);
        $service = app(MonnifyFundingService::class);
        $account = $service->reserve($user, '', '01234567890', true);
        $this->assertSame('uncertain', $account->status);
        $repeat = $service->reserve($user, '', '01234567890', true);
        $this->assertSame($account->reference, $repeat->reference);
        $recovered = $service->refreshAccount($user);
        $this->assertSame('active', $recovered->status);
        $this->assertSame($account->id, $recovered->id);
        $this->assertSame(1, $attempts);
    }

    public function test_customers_cannot_see_or_refresh_other_users_bank_accounts(): void
    {
        $account = MonnifyAccount::factory()->create(['accounts' => [['bankCode' => '50515', 'bankName' => 'Moniepoint', 'accountName' => 'OTHER CUSTOMER', 'accountNumber' => '6254727989']]]);
        $this->actingAs(User::factory()->create());
        Livewire::test(WalletFunding::class)->assertDontSee('6254727989')->assertDontSee('OTHER CUSTOMER');
        $this->assertNull(app(MonnifyFundingService::class)->refreshAccount(auth()->user()));
        Http::assertNothingSent();
        $this->actingAs(User::factory()->suspended()->create());
        Livewire::test(WalletFunding::class)->assertForbidden();
    }

    public function test_signed_webhooks_are_persisted_and_queued_before_any_wallet_credit(): void
    {
        Queue::fake([VerifyMonnifyPayment::class]);
        $account = MonnifyAccount::factory()->create();
        $event = $this->event($account);
        $this->webhook($event)->assertOk()->assertJsonPath('status', 'received');
        $payment = MonnifyPayment::query()->sole();
        $this->assertSame('pending', $payment->status);
        $this->assertSame(200, $payment->charge_basis_points);
        $this->assertSame(0, $account->user->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
        Queue::assertPushed(VerifyMonnifyPayment::class, fn ($job): bool => $job->paymentId === $payment->id && $job->connection === 'database');
        Http::assertNothingSent();
    }

    #[TestWith(['missing'])]
    #[TestWith(['wrong'])]
    #[TestWith(['tampered'])]
    #[TestWith(['concatenated_hash'])]
    public function test_webhooks_with_invalid_signatures_never_queue_or_credit_payments(string $scenario): void
    {
        Queue::fake();
        $account = MonnifyAccount::factory()->create();
        $raw = json_encode($this->event($account));
        $signature = match ($scenario) {
            'missing' => '',
            'wrong' => str_repeat('0', 128),
            'concatenated_hash' => hash('sha512', 'test-secret-key'.$raw),
            default => hash_hmac('sha512', $raw.'modified', 'test-secret-key'),
        };
        $this->rawWebhook($raw, $signature)->assertUnauthorized();
        $this->assertDatabaseCount('monnify_payments', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_malformed_signed_json_and_mismatched_destinations_are_rejected(): void
    {
        Queue::fake();
        $this->rawWebhook('not json', hash_hmac('sha512', 'not json', 'test-secret-key'))->assertStatus(400);
        $account = MonnifyAccount::factory()->create();
        $event = $this->event($account);
        $event['eventData']['destinationAccountInformation']['accountNumber'] = '1111111111';
        $this->webhook($event)->assertStatus(422);
        $this->assertDatabaseCount('monnify_payments', 0);
        Queue::assertNothingPushed();
    }

    #[TestWith(['FAILED'])]
    #[TestWith(['PENDING'])]
    #[TestWith(['PARTIALLY_PAID'])]
    public function test_non_paid_notifications_are_acknowledged_without_crediting(string $status): void
    {
        Queue::fake();
        $account = MonnifyAccount::factory()->create();
        $event = $this->event($account);
        $event['eventData']['paymentStatus'] = $status;
        $this->webhook($event)->assertOk()->assertJsonPath('status', 'ignored');
        $this->assertDatabaseCount('monnify_payments', 0);
        Queue::assertNothingPushed();
    }

    public function test_sandbox_webhooks_never_credit_real_wallets_even_without_a_signature(): void
    {
        Queue::fake();
        MonnifySettings::save([...$this->settings(), 'mode' => 'sandbox']);
        $account = MonnifyAccount::factory()->create(['mode' => 'sandbox']);
        $event = $this->event($account);
        $this->rawWebhook(json_encode($event), '')->assertOk()->assertJsonPath('status', 'ignored');
        $this->assertDatabaseCount('monnify_payments', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_verified_funding_deducts_only_the_configured_percentage_and_creates_one_ledger_entry(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['wallet_balance' => 50000]);
        $account = MonnifyAccount::factory()->create(['user_id' => $owner->id]);
        $event = $this->event($account);
        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk();
        $payment = MonnifyPayment::query()->sole();
        $this->fakeVerification($event['eventData']);
        $service = app(MonnifyFundingService::class);
        $service->verifyPayment($payment->id);
        $service->verifyPayment($payment->id);
        $this->webhook($event)->assertOk();
        $this->assertSame(148000, $owner->fresh()->wallet_balance);
        $payment->refresh();
        $this->assertSame('credited', $payment->status);
        $this->assertSame(100000, $payment->amount_paid);
        $this->assertSame(99000, $payment->settlement_amount);
        $this->assertSame(2000, $payment->fee_amount);
        $this->assertSame(98000, $payment->credited_amount);
        $this->assertSame(98000, $payment->walletTransaction->amount);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertSentCount(2);
        Queue::assertPushed(VerifyMonnifyPayment::class, 1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://api.monnify.com/api/v2/merchant/transactions/query?')
            && $request['transactionReference'] === $payment->transaction_reference
            && $request->hasHeader('Authorization', 'Bearer test-access-token'));
    }

    #[TestWith(['10.00', '0.05', 1, 4])]
    #[TestWith(['0.00', '1000.00', 0, 100000])]
    #[TestWith(['100.00', '1000.00', 100000, 0])]
    #[TestWith(['2.50', '1000.00', 2500, 97500])]
    public function test_fee_calculations_use_exact_kobo_and_round_half_up(string $percent, string $amount, int $fee, int $credit): void
    {
        Queue::fake();
        MonnifySettings::save([...$this->settings(), 'funding_charge' => $percent]);
        $account = MonnifyAccount::factory()->create();
        $event = $this->event($account);
        $event['eventData']['amountPaid'] = $amount;
        $event['eventData']['settlementAmount'] = $amount;
        $this->webhook($event)->assertOk();
        $payment = MonnifyPayment::query()->sole();
        $this->fakeVerification($event['eventData']);
        app(MonnifyFundingService::class)->verifyPayment($payment->id);
        $this->assertSame($fee, $payment->fresh()->fee_amount);
        $this->assertSame($credit, $payment->fresh()->credited_amount);
        $this->assertSame($credit, $account->user->fresh()->wallet_balance);
    }

    public function test_fee_changes_do_not_change_an_already_received_payment_and_disabled_creation_does_not_block_existing_deposits(): void
    {
        Queue::fake();
        $account = MonnifyAccount::factory()->create();
        $event = $this->event($account);
        $this->webhook($event)->assertOk();
        MonnifySettings::save([...$this->settings(), 'funding_charge' => '5.00', 'enabled' => false]);
        $this->webhook($event)->assertOk();
        $this->fakeVerification($event['eventData']);
        $payment = MonnifyPayment::query()->sole();
        app(MonnifyFundingService::class)->verifyPayment($payment->id);
        $this->assertSame(2000, $payment->fresh()->fee_amount);
        $this->assertSame(98000, $account->user->fresh()->wallet_balance);
    }

    #[TestWith(['transactionReference', 'WRONG-TRANSACTION'])]
    #[TestWith(['paymentReference', 'WRONG-PAYMENT'])]
    #[TestWith(['currency', 'USD'])]
    #[TestWith(['amountPaid', '1100.00'])]
    #[TestWith(['settlementAmount', '980.00'])]
    #[TestWith(['paymentMethod', 'CARD'])]
    #[TestWith(['paymentStatus', 'FAILED'])]
    public function test_mismatched_verified_payment_details_never_credit_the_wallet(string $field, string $value): void
    {
        Queue::fake();
        $account = MonnifyAccount::factory()->create();
        $event = $this->event($account);
        $this->webhook($event)->assertOk();
        $body = $event['eventData'];
        $body[$field] = $value;
        $this->fakeVerification($body);
        $payment = MonnifyPayment::query()->sole();
        app(MonnifyFundingService::class)->verifyPayment($payment->id);
        $this->assertSame('review', $payment->fresh()->status);
        $this->assertSame(0, $account->user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_verified_transactions_for_a_different_reserved_account_are_not_credited(): void
    {
        Queue::fake();
        $account = MonnifyAccount::factory()->create();
        $event = $this->event($account);
        $this->webhook($event)->assertOk();
        $body = $event['eventData'];
        $body['product']['reference'] = 'ANOTHER-ACCOUNT';
        $this->fakeVerification($body);
        $payment = MonnifyPayment::query()->sole();
        app(MonnifyFundingService::class)->verifyPayment($payment->id);
        $this->assertSame('review', $payment->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_temporary_provider_failure_leaves_funding_pending_for_a_safe_verification_retry(): void
    {
        Queue::fake();
        $account = MonnifyAccount::factory()->create();
        $event = $this->event($account);
        $this->webhook($event)->assertOk();
        Http::fake([
            'https://api.monnify.com/api/v1/auth/login' => Http::response($this->envelope(['accessToken' => 'test-access-token'])),
            'https://api.monnify.com/api/v2/merchant/transactions/query*' => Http::sequence()->push('unavailable', 503)->push($this->envelope($event['eventData'])),
        ]);
        $payment = MonnifyPayment::query()->sole();
        try {
            app(MonnifyFundingService::class)->verifyPayment($payment->id);
            $this->fail('The queued verification must be retried.');
        } catch (RuntimeException) {
            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertSame(0, $account->user->fresh()->wallet_balance);
        }
        app(MonnifyFundingService::class)->verifyPayment($payment->id);
        $this->assertSame('credited', $payment->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_wallet_limit_failures_and_terminal_job_failures_do_not_create_partial_credits(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['wallet_balance' => Money::MAX_MINOR]);
        $account = MonnifyAccount::factory()->create(['user_id' => $owner->id]);
        $event = $this->event($account);
        $this->webhook($event)->assertOk();
        $payment = MonnifyPayment::query()->sole();
        $this->fakeVerification($event['eventData']);
        app(MonnifyFundingService::class)->verifyPayment($payment->id);
        $this->assertSame('review', $payment->fresh()->status);
        $this->assertSame(Money::MAX_MINOR, $owner->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
        (new VerifyMonnifyPayment($payment->id))->failed(new RuntimeException('Provider unavailable'));
        $this->assertSame('review', $payment->fresh()->status);
    }

    public function test_received_payment_and_database_queue_job_are_written_in_the_same_transaction(): void
    {
        $account = MonnifyAccount::factory()->create();
        DB::beginTransaction();
        try {
            $this->webhook($this->event($account))->assertOk();
            $this->assertDatabaseCount('monnify_payments', 1);
            $this->assertDatabaseCount('jobs', 1);
            $this->assertStringNotContainsString('test-secret-key', DB::table('jobs')->value('payload'));
            $this->assertDatabaseCount('wallet_transactions', 0);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('monnify_payments', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_a_late_mismatched_verification_cannot_downgrade_an_already_credited_payment(): void
    {
        Queue::fake();
        $account = MonnifyAccount::factory()->create();
        $event = $this->event($account);
        $this->webhook($event)->assertOk();
        $payment = MonnifyPayment::query()->sole();
        $queries = 0;
        Http::fake([
            'https://api.monnify.com/api/v1/auth/login' => Http::response($this->envelope(['accessToken' => 'test-access-token'])),
            'https://api.monnify.com/api/v2/merchant/transactions/query*' => function () use (&$queries, $payment, $event) {
                if (++$queries === 1) {
                    MonnifyPayment::query()->whereKey($payment->id)->update(['processing_started_at' => now()->subMinutes(2)]);
                    app(MonnifyFundingService::class)->verifyPayment($payment->id);

                    return Http::response($this->envelope([...$event['eventData'], 'transactionReference' => 'OLD-WORKER-RESPONSE']));
                }

                return Http::response($this->envelope($event['eventData']));
            },
        ]);
        app(MonnifyFundingService::class)->verifyPayment($payment->id);
        $this->assertSame('credited', $payment->fresh()->status);
        $this->assertSame(98000, $account->user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertSentCount(4);
    }

    public function test_live_credentials_cannot_be_cleared_by_disabling_new_account_creation(): void
    {
        MonnifyAccount::factory()->create();
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(MonnifyConfiguration::class)->set('enabled', false)->set('apiKey', ' ')->set('secretKey', ' ')
            ->call('save')->assertHasErrors(['api_key', 'secret_key']);
        $this->assertSame('test-api-key', MonnifySettings::get()['api_key']);
        $this->assertSame('test-secret-key', MonnifySettings::get()['secret_key']);
    }

    public function test_pending_payment_rechecks_are_scoped_to_the_logged_in_customer(): void
    {
        Queue::fake();
        $other = MonnifyPayment::factory()->create(['status' => 'review']);
        $owner = User::factory()->create();
        $account = MonnifyAccount::factory()->create(['user_id' => $owner->id]);
        $own = MonnifyPayment::factory()->create(['monnify_account_id' => $account->id, 'status' => 'review']);
        $this->actingAs($owner);
        Livewire::test(WalletFunding::class)->assertDontSee($other->transaction_reference)->call('checkPayments');
        Queue::assertPushed(VerifyMonnifyPayment::class, fn ($job): bool => $job->paymentId === $own->id);
        Queue::assertPushed(VerifyMonnifyPayment::class, 1);
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        return ['enabled' => true, 'mode' => 'live', 'api_key' => 'test-api-key', 'secret_key' => 'test-secret-key', 'contract_code' => '1234567890', 'funding_charge' => '2.00'];
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    private function envelope(array $body): array
    {
        return ['requestSuccessful' => true, 'responseMessage' => 'success', 'responseCode' => '0', 'responseBody' => $body];
    }

    private function fakeCreation(): void
    {
        Http::fake([
            'https://api.monnify.com/api/v1/auth/login' => Http::response($this->envelope(['accessToken' => 'test-access-token', 'expiresIn' => 3600])),
            'https://api.monnify.com/api/v2/bank-transfer/reserved-accounts' => fn (Request $request) => Http::response($this->envelope($this->accountBody(MonnifyAccount::query()->where('reference', $request['accountReference'])->sole()))),
        ]);
    }

    /** @return array<string, mixed> */
    private function accountBody(MonnifyAccount $account): array
    {
        return [
            'contractCode' => $account->contract_code, 'accountReference' => $account->reference, 'accountName' => $account->customer_name,
            'customerName' => $account->customer_name, 'customerEmail' => $account->customer_email,
            'currencyCode' => 'NGN', 'collectionChannel' => 'RESERVED_ACCOUNT', 'reservationReference' => 'NWA7DMJ0W2UDK1KN5SLF',
            'reservedAccountType' => 'GENERAL', 'status' => 'ACTIVE',
            'accounts' => [['bankCode' => '50515', 'bankName' => 'Moniepoint Microfinance Bank', 'accountNumber' => '6254727989', 'accountName' => $account->customer_name]],
        ];
    }

    /** @return array<string, mixed> */
    private function event(MonnifyAccount $account): array
    {
        return [
            'eventType' => 'SUCCESSFUL_TRANSACTION',
            'eventData' => [
                'product' => ['reference' => $account->reference, 'type' => 'RESERVED_ACCOUNT'],
                'transactionReference' => 'MNFY|44|20220407112123|000684', 'paymentReference' => 'PAY-001',
                'paidOn' => '2026-11-15 12:00:00', 'paymentDescription' => 'Wallet funding',
                'paymentSourceInformation' => [], 'destinationAccountInformation' => ['bankCode' => $account->accounts[0]['bankCode'], 'bankName' => $account->accounts[0]['bankName'], 'accountNumber' => $account->accounts[0]['accountNumber']],
                'amountPaid' => '1000.00', 'totalPayable' => '1000.00', 'settlementAmount' => '990.00',
                'paymentMethod' => 'ACCOUNT_TRANSFER', 'paymentStatus' => 'PAID', 'currency' => 'NGN',
                'customer' => ['email' => $account->customer_email, 'name' => $account->customer_name],
            ],
        ];
    }

    /** @param array<string, mixed> $body */
    private function fakeVerification(array $body): void
    {
        Http::fake([
            'https://api.monnify.com/api/v1/auth/login' => Http::response($this->envelope(['accessToken' => 'test-access-token', 'expiresIn' => 3600])),
            'https://api.monnify.com/api/v2/merchant/transactions/query*' => Http::response($this->envelope($body)),
        ]);
    }

    /** @param array<string, mixed> $event */
    private function webhook(array $event): TestResponse
    {
        $raw = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->rawWebhook($raw, hash_hmac('sha512', $raw, 'test-secret-key'));
    }

    private function rawWebhook(string $raw, string $signature): TestResponse
    {
        return $this->call('POST', route('monnify.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_MONNIFY_SIGNATURE' => $signature], $raw);
    }
}
