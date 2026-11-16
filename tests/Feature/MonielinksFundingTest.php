<?php

namespace Tests\Feature;

use App\Livewire\Admin\MonielinksConfiguration;
use App\Livewire\MonielinksFunding;
use App\Models\AdminAudit;
use App\Models\MonielinksAccount;
use App\Models\MonielinksPayment;
use App\Models\SiteSetting;
use App\Models\User;
use App\MonielinksClient;
use App\MonielinksFundingService;
use App\MonielinksSettings;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class MonielinksFundingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_admin_can_save_credentials_and_percentage_in_database_without_exposing_keys(): void
    {
        MonielinksSettings::save($this->settings());
        $this->actingAs(User::factory()->administrator()->create());
        $component = Livewire::test(MonielinksConfiguration::class)
            ->assertSet('clientId', '')->assertSet('secretKey', '')->assertSet('webhookSecret', '')
            ->assertDontSee('test-client')->assertDontSee('test-secret')->assertDontSee('test-webhook-secret')
            ->set('clientId', 'new-client')->set('secretKey', 'new-secret')->set('webhookSecret', 'new-signing-secret')
            ->set('fundingCharge', '2.50')->set('enabled', true)->call('save')->assertHasNoErrors()
            ->assertSet('clientId', '')->assertSet('secretKey', '')->assertSet('webhookSecret', '');
        $raw = json_decode(SiteSetting::query()->where('key', 'monielinks')->value('value'), true);
        $this->assertSame('new-client', $raw['client_id']);
        $this->assertSame('new-secret', $raw['secret_key']);
        $this->assertSame('new-signing-secret', $raw['webhook_secret']);
        $component->set('fundingCharge', '3.00')->call('save')->assertHasNoErrors();
        $this->assertSame('new-secret', MonielinksSettings::get()['secret_key']);
        $this->assertSame('3.00', MonielinksSettings::get()['funding_charge']);
        $audit = json_encode(AdminAudit::query()->get()->toArray());
        $this->assertStringNotContainsString('new-secret', $audit);
        $this->assertStringNotContainsString('new-signing-secret', $audit);
        Http::assertNothingSent();
    }

    public function test_settings_are_restricted_to_authorized_staff(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(MonielinksConfiguration::class)->assertForbidden();
        $this->actingAs(User::factory()->staff(['settings.view'])->create());
        Livewire::test(MonielinksConfiguration::class)->call('save')->assertForbidden();
        $this->actingAs(User::factory()->staff(['dashboard.view'])->create());
        Livewire::test(MonielinksConfiguration::class)->assertForbidden();
    }

    #[TestWith(['-1'])]
    #[TestWith(['100.01'])]
    #[TestWith(['1.234'])]
    #[TestWith(['abc'])]
    public function test_invalid_funding_percentage_is_rejected(string $percentage): void
    {
        MonielinksSettings::save($this->settings());
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(MonielinksConfiguration::class)->set('fundingCharge', $percentage)->call('save')
            ->assertHasErrors(['funding_charge']);
        $this->assertSame('2.00', MonielinksSettings::get()['funding_charge']);
    }

    public function test_existing_accounts_keep_their_merchant_credentials_when_new_creation_is_disabled(): void
    {
        MonielinksSettings::save($this->settings());
        MonielinksAccount::factory()->create();
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(MonielinksConfiguration::class)->set('clientId', 'another-client')->call('save')
            ->assertHasErrors(['client_id']);
        Livewire::test(MonielinksConfiguration::class)->set('enabled', false)->call('save')->assertHasNoErrors();
        $this->assertFalse(MonielinksSettings::get()['enabled']);
        $this->assertSame('test-client', MonielinksSettings::get()['client_id']);
        $this->assertSame('test-webhook-secret', MonielinksSettings::get()['webhook_secret']);
    }

    public function test_customer_creates_one_palmpay_account_using_profile_details_and_the_documented_requests(): void
    {
        MonielinksSettings::save($this->settings());
        $user = User::factory()->create(['first_name' => 'Johnwick', 'surname' => 'Pans', 'email' => 'customer@example.com']);
        $this->actingAs($user);
        $this->fakeCreation('customer@example.com');
        Livewire::test(MonielinksFunding::class)->call('createAccount')->assertHasNoErrors()
            ->assertSee('6632388120')->assertSee('John(ALFAHSUB)')->assertSee('PalmPay Bank');
        app(MonielinksFundingService::class)->reserve($user);
        $account = MonielinksAccount::query()->sole();
        $this->assertSame($user->id, $account->user_id);
        $this->assertSame('active', $account->status);
        $this->assertSame('customer@example.com', $account->customer_email);
        $this->assertSame('customer@example.com', $account->reference);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === MonielinksClient::BASE_URL.'/authentication/token'
            && $request->data() === ['client_id' => 'test-client', 'secret_id' => 'test-secret']);
        Http::assertSent(fn (Request $request): bool => $request->url() === MonielinksClient::BASE_URL.'/virtual-account/create'
            && $request->hasHeader('Authorization', 'Bearer access-for-test')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === ['email' => 'customer@example.com', 'firstName' => 'Johnwick', 'lastName' => 'Pans', 'bank' => 'PALMPAY']);
    }

    public function test_disabled_creation_and_missing_profile_names_do_not_call_provider(): void
    {
        MonielinksSettings::save($this->settings(['enabled' => false]));
        $this->actingAs(User::factory()->create(['surname' => null]));
        Livewire::test(MonielinksFunding::class)->call('createAccount')->assertHasErrors(['funding']);
        MonielinksSettings::save($this->settings());
        Livewire::test(MonielinksFunding::class)->call('createAccount')->assertHasErrors(['last_name']);
        $this->assertDatabaseCount('monielinks_accounts', 0);
        Http::assertNothingSent();
    }

    public function test_guest_and_suspended_customers_cannot_create_accounts(): void
    {
        Livewire::test(MonielinksFunding::class)->assertForbidden();
        $this->actingAs(User::factory()->suspended()->create());
        Livewire::test(MonielinksFunding::class)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_account_timeout_is_uncertain_and_is_never_automatically_resubmitted(): void
    {
        MonielinksSettings::save($this->settings());
        $user = User::factory()->create();
        Http::fake([
            MonielinksClient::BASE_URL.'/authentication/token' => Http::response(['data' => ['access_token' => 'access-for-test']]),
            MonielinksClient::BASE_URL.'/virtual-account/create' => Http::failedConnection(),
        ]);
        $account = app(MonielinksFundingService::class)->reserve($user);
        $this->assertSame('uncertain', $account->status);
        $again = app(MonielinksFundingService::class)->reserve($user);
        $this->assertSame($account->id, $again->id);
        $this->actingAs($user);
        Livewire::test(MonielinksFunding::class)->assertSee('uncertain')->assertDontSee('Create PalmPay account');
        Http::assertSentCount(2);
        $this->assertDatabaseCount('monielinks_accounts', 1);
    }

    public function test_authentication_failure_does_not_create_an_external_account_and_allows_a_later_explicit_attempt(): void
    {
        MonielinksSettings::save($this->settings());
        $user = User::factory()->create();
        Http::fake([
            MonielinksClient::BASE_URL.'/authentication/token' => Http::sequence()
                ->push(['type' => 'error'], 401)->push(['data' => ['access_token' => 'access-for-test']]),
            MonielinksClient::BASE_URL.'/virtual-account/create' => Http::response([
                'type' => 'success', 'data' => ['account' => [
                    'account_number' => '6632388120', 'account_name' => 'TEST CUSTOMER', 'bank' => 'PalmPay Bank',
                    'email' => $user->email, 'reference' => $user->email,
                ]],
            ]),
        ]);
        $account = app(MonielinksFundingService::class)->reserve($user);
        $this->assertSame('failed', $account->status);
        Http::assertSentCount(1);
        $account = app(MonielinksFundingService::class)->reserve($user);
        $this->assertSame('active', $account->status);
        $this->assertDatabaseCount('monielinks_accounts', 1);
        Http::assertSentCount(3);
    }

    #[TestWith(['email', 'another@example.com'])]
    #[TestWith(['account_number', '1234'])]
    #[TestWith(['bank', 'Different Bank'])]
    public function test_unexpected_account_details_are_not_displayed_as_an_active_account(string $field, string $value): void
    {
        MonielinksSettings::save($this->settings());
        $user = User::factory()->create();
        $this->fakeCreation($user->email, [$field => $value]);
        $account = app(MonielinksFundingService::class)->reserve($user);
        $this->assertSame('uncertain', $account->status);
        $this->assertNull($account->account_number);
        Http::assertSentCount(2);
    }

    public function test_only_the_customer_own_account_is_visible_even_when_creation_is_disabled(): void
    {
        MonielinksSettings::save($this->settings(['enabled' => false]));
        $user = User::factory()->create();
        MonielinksAccount::factory()->for($user)->create(['account_number' => '6632388120']);
        MonielinksAccount::factory()->create(['account_number' => '7777777777']);
        $this->actingAs($user);
        Livewire::test(MonielinksFunding::class)->assertSee('6632388120')->assertDontSee('7777777777');
        Http::assertNothingSent();
    }

    public function test_missing_or_invalid_signature_is_rejected_without_saving_or_crediting(): void
    {
        MonielinksSettings::save($this->settings());
        $event = $this->event();
        $this->postJson(route('monielinks.webhook'), $event)->assertUnauthorized();
        $this->postJson(route('monielinks.webhook'), $event, ['X-Signature' => str_repeat('0', 64)])->assertUnauthorized();
        $this->assertDatabaseCount('monielinks_payments', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_signed_payment_is_matched_by_account_number_and_saved_once_for_review(): void
    {
        MonielinksSettings::save($this->settings());
        $user = User::factory()->create(['wallet_balance' => 5000, 'email' => 'johndoe@example.com']);
        $account = MonielinksAccount::factory()->for($user)->create(['customer_email' => $user->email, 'account_number' => '6632388120']);
        $this->postSigned($this->event())->assertOk()->assertJsonPath('status', 'received');
        $this->postSigned($this->event())->assertOk();
        $payment = MonielinksPayment::query()->sole();
        $this->assertSame('review', $payment->status);
        $this->assertSame(120000, $payment->amount_paid);
        $this->assertSame(200, $payment->charge_basis_points);
        $this->assertSame($account->id, $payment->monielinks_account_id);
        $this->assertSame(5000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(MonielinksConfiguration::class)->assertSee('MI2115907121685118976')->assertSee($user->name)->assertSee('6632388120');
        Http::assertNothingSent();
    }

    public function test_same_transaction_with_different_amount_is_rejected_without_overwriting_original(): void
    {
        MonielinksSettings::save($this->settings());
        MonielinksAccount::factory()->create(['account_number' => '6632388120']);
        $this->postSigned($this->event())->assertOk();
        $event = $this->event();
        $event['data']['amount'] = '999.00';
        $this->postSigned($event)->assertUnprocessable();
        $this->assertSame(120000, MonielinksPayment::query()->sole()->amount_paid);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_unsigned_webhook_is_saved_as_unverified_even_when_creation_is_disabled(): void
    {
        MonielinksSettings::save($this->settings(['webhook_secret' => '', 'enabled' => false]));
        $user = User::factory()->create(['wallet_balance' => 5000]);
        $account = MonielinksAccount::factory()->for($user)->create(['account_number' => '6632388120']);
        $this->postJson(route('monielinks.webhook'), $this->event())->assertOk();
        $this->postJson(route('monielinks.webhook'), $this->event())->assertOk();
        $this->assertDatabaseCount('monielinks_payments', 1);
        $payment = MonielinksPayment::query()->sole();
        $this->assertSame('unverified', $payment->status);
        $this->assertSame($account->id, $payment->monielinks_account_id);
        $this->assertSame(5000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->actingAs($user);
        Livewire::test(MonielinksFunding::class)->assertDontSee('MI2115907121685118976');
    }

    public function test_unknown_account_is_ignored_and_masked_account_number_is_rejected(): void
    {
        MonielinksSettings::save($this->settings(['webhook_secret' => '']));
        MonielinksAccount::factory()->create(['account_number' => '9999999999']);
        $this->postJson(route('monielinks.webhook'), $this->event())->assertOk()->assertJsonPath('status', 'ignored');
        $event = $this->event();
        $event['data']['account_number'] = '******4420';
        $this->postJson(route('monielinks.webhook'), $event)->assertUnprocessable();
        $this->assertDatabaseCount('monielinks_payments', 0);
    }

    public function test_email_cannot_redirect_a_payment_to_a_different_account(): void
    {
        MonielinksSettings::save($this->settings(['webhook_secret' => '']));
        $receiving = MonielinksAccount::factory()->create(['account_number' => '6632388120']);
        $other = MonielinksAccount::factory()->create(['account_number' => '9999999999', 'customer_email' => 'customer@example.com']);
        $this->postJson(route('monielinks.webhook'), $this->event())->assertOk();
        $payment = MonielinksPayment::query()->sole();
        $this->assertSame($receiving->id, $payment->monielinks_account_id);
        $this->assertNotSame($other->id, $payment->monielinks_account_id);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_accountnumber_alone_identifies_the_owner_even_with_unusable_email(): void
    {
        MonielinksSettings::save($this->settings(['webhook_secret' => '']));
        $account = MonielinksAccount::factory()->create(['account_number' => '6632388120']);
        $event = $this->event();
        unset($event['data']['account_number']);
        $event['data']['accountnumber'] = '6632388120';
        $event['data']['email'] = 'not-an-email';
        $this->postJson(route('monielinks.webhook'), $event)->assertOk()->assertJsonPath('status', 'received');
        $this->assertSame($account->id, MonielinksPayment::query()->sole()->monielinks_account_id);
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(MonielinksConfiguration::class)->assertSee('6632388120')->assertSee($account->user->name);
    }

    public function test_conflicting_accountnumber_fields_are_rejected_without_assigning_a_user(): void
    {
        MonielinksSettings::save($this->settings(['webhook_secret' => '']));
        MonielinksAccount::factory()->create(['account_number' => '6632388120']);
        $event = $this->event();
        $event['data']['accountnumber'] = '9999999999';
        $this->postJson(route('monielinks.webhook'), $event)->assertUnprocessable();
        $this->assertDatabaseCount('monielinks_payments', 0);
    }

    public function test_later_authenticated_notification_can_replace_forged_unsigned_details_without_crediting(): void
    {
        MonielinksSettings::save($this->settings(['webhook_secret' => '']));
        MonielinksAccount::factory()->create(['account_number' => '6632388120']);
        $forged = $this->event();
        $forged['data']['amount'] = '1.00';
        $this->postJson(route('monielinks.webhook'), $forged)->assertOk();
        MonielinksSettings::save($this->settings());
        $this->postSigned($this->event())->assertOk();
        $payment = MonielinksPayment::query()->sole();
        $this->assertSame('review', $payment->status);
        $this->assertSame(120000, $payment->amount_paid);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_duplicate_transaction_cannot_be_reassigned_to_another_account(): void
    {
        MonielinksSettings::save($this->settings());
        $account = MonielinksAccount::factory()->create(['account_number' => '6632388120']);
        MonielinksAccount::factory()->create(['account_number' => '9999999999']);
        $this->postSigned($this->event())->assertOk();
        $event = $this->event();
        $event['data']['account_number'] = '9999999999';
        $this->postSigned($event)->assertUnprocessable();
        $this->assertSame($account->id, MonielinksPayment::query()->sole()->monielinks_account_id);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    #[TestWith(['amount', '0.00'])]
    #[TestWith(['amount', '-1.00'])]
    #[TestWith(['amount', '1.001'])]
    #[TestWith(['currency', 'USD'])]
    #[TestWith(['status', 'failed'])]
    public function test_invalid_payment_details_do_not_credit_wallets(string $field, string $value): void
    {
        MonielinksSettings::save($this->settings());
        $event = $this->event();
        $event['data'][$field] = $value;
        $this->postSigned($event)->assertUnprocessable();
        $this->assertDatabaseCount('monielinks_payments', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_failed_events_are_ignored_and_tampered_raw_json_is_rejected(): void
    {
        MonielinksSettings::save($this->settings());
        $event = $this->event();
        $event['event'] = 'payment.failed';
        $this->postSigned($event)->assertOk()->assertJsonPath('status', 'ignored');
        $raw = json_encode($this->event(), JSON_THROW_ON_ERROR);
        $this->call('POST', route('monielinks.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $raw, 'test-webhook-secret'),
        ], $raw.' ')->assertUnauthorized();
        $this->assertDatabaseCount('monielinks_payments', 0);
    }

    public function test_malformed_signed_json_returns_400_and_html_in_account_name_is_escaped(): void
    {
        MonielinksSettings::save($this->settings());
        $raw = '{broken json';
        $this->call('POST', route('monielinks.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $raw, 'test-webhook-secret'),
        ], $raw)->assertBadRequest();
        $user = User::factory()->create();
        MonielinksAccount::factory()->for($user)->create(['account_name' => '<script>alert(1)</script>']);
        $this->actingAs($user);
        Livewire::test(MonielinksFunding::class)->assertSee('<script>alert(1)</script>')
            ->assertDontSeeHtml('<script>alert(1)</script>');
    }

    /** @param array<string, mixed> $overrides
     * @return array{enabled: bool, client_id: string, secret_key: string, webhook_secret: string, funding_charge: string}
     */
    private function settings(array $overrides = []): array
    {
        return array_replace(['enabled' => true, 'client_id' => 'test-client', 'secret_key' => 'test-secret', 'webhook_secret' => 'test-webhook-secret', 'funding_charge' => '2.00'], $overrides);
    }

    /** @param array<string, string> $overrides */
    private function fakeCreation(string $email, array $overrides = []): void
    {
        Http::fake([
            MonielinksClient::BASE_URL.'/authentication/token' => Http::response(['data' => ['access_token' => 'access-for-test']]),
            MonielinksClient::BASE_URL.'/virtual-account/create' => Http::response([
                'message' => ['Virtual account created successfully'], 'type' => 'success',
                'data' => ['account' => array_replace([
                    'account_number' => '6632388120', 'account_name' => 'John(ALFAHSUB)', 'bank' => 'PalmPay Bank',
                    'email' => $email, 'reference' => $email,
                ], $overrides)],
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function event(): array
    {
        return [
            'event' => 'payment.success', 'message' => 'PalmPay VA credit successful',
            'data' => [
                'trx_id' => 'MI2115907121685118976', 'amount' => '1200.00', 'currency' => 'NGN', 'status' => 'successful',
                'reference' => 'customer@example.com', 'timestamp' => '2026-10-29T21:42:38+01:00',
                'email' => 'customer@example.com', 'account_number' => '6632388120',
            ],
        ];
    }

    /** @param array<string, mixed> $event */
    private function postSigned(array $event): TestResponse
    {
        $raw = json_encode($event, JSON_THROW_ON_ERROR);

        return $this->call('POST', route('monielinks.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $raw, 'test-webhook-secret'),
        ], $raw);
    }
}
