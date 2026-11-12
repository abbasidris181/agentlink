<?php

namespace Tests\Feature;

use App\Livewire\NinVerification;
use App\Models\NinVerificationRecord;
use App\Models\ServicePrice;
use App\Models\SiteSetting;
use App\Models\User;
use App\NinVerificationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NinV2ProviderTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.verify.url' => 'https://verify.example.test/', 'services.verify.token' => 'test-token']);
        Http::preventStrayRequests();
    }

    public function test_v2_uses_the_v1_provider_endpoint_with_v2_pricing_and_charges_once(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'nin', 'amount' => 15000]);
        ServicePrice::factory()->create(['service' => 'nin-v2', 'method' => 'nin', 'amount' => 20000]);
        Http::fake(['https://verify.example.test/api/v1/verify' => Http::response($this->providerResponse())]);
        $this->actingAs($user);

        $component = Livewire::test(NinVerification::class, ['version' => 'v2'])
            ->set('nin', '01234567890')->set('consent', true)->call('verify')->assertHasNoErrors();
        $record = NinVerificationRecord::query()->sole();
        $component->assertRedirect(route('nin.v2.result', ['verification' => $record->reference]));
        $this->assertSame('v2', $record->version);
        $this->assertSame('successful', $record->status);
        $this->assertSame($this->providerResponse()['message'], $record->result);
        $this->assertSame(80000, $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', ['id' => $record->wallet_transaction_id, 'service' => 'NIN V2', 'amount' => '200.00']);
        $this->assertStringNotContainsString('01234567890', $record->getRawOriginal('nin'));
        $this->assertStringNotContainsString('Taylor', $record->getRawOriginal('result'));
        $component->call('verify')->assertHasNoErrors();
        $this->assertDatabaseCount('nin_verification_records', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://verify.example.test/api/v1/verify'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === ['idNumber' => '01234567890', 'type' => 'nin', 'consent' => true]);
        $this->get(route('nin.v2'))->assertOk()->assertSee($record->reference)
            ->assertSee('href="'.route('nin.v2.result', ['verification' => $record->reference]).'"', false);
        $this->get(route('nin.v1'))->assertOk()->assertDontSee($record->reference);
        try {
            app(NinVerificationService::class)->verify($user, '01234567890', $record->request_key);
            $this->fail('A V2 reference must not be replayed as V1.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('verification', $exception->errors());
        }
        Http::assertSentCount(1);
    }

    #[TestWith(['regular'])]
    #[TestWith(['standard'])]
    #[TestWith(['premium'])]
    #[TestWith(['digital'])]
    public function test_v2_results_and_slips_use_v2_routes_and_are_private(string $slip): void
    {
        $record = NinVerificationRecord::factory()->create(['version' => 'v2', 'result' => $this->providerResponse()['message']]);
        $result = route('nin.v2.result', ['verification' => $record->reference]);
        $download = route('nin.v2.slip', ['verification' => $record->reference, 'slip' => $slip]);
        $this->get($result)->assertRedirect();
        $this->actingAs(User::factory()->create())->get($result)->assertNotFound();
        $this->get($download)->assertNotFound();
        $this->actingAs($record->user)->get($result)->assertOk()->assertSee('NIN V2')->assertSee('Taylor')
            ->assertSee(route('nin.v2'))->assertSee($download, false);
        $this->get($download)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('nin.v1.result', ['verification' => $record->reference]))->assertNotFound();
        $v1 = NinVerificationRecord::factory()->create(['user_id' => $record->user_id]);
        $this->get(route('nin.v2.result', ['verification' => $v1->reference]))->assertNotFound();
        $this->get(route('nin.v2'))->assertOk()->assertDontSee($v1->reference);
    }

    #[TestWith([true, 200, 'No record found', 'charged_error', 80000])]
    #[TestWith([false, 200, 'No record found', 'failed', 100000])]
    #[TestWith([true, 503, 'Service unavailable', 'failed', 100000])]
    #[TestWith([true, 200, 'Insufficient provider balance', 'failed', 100000])]
    #[TestWith([true, 200, 'No record found', 'charged_error', 80000, 'phone'])]
    #[TestWith([true, 503, 'Service unavailable', 'failed', 100000, 'phone'])]
    public function test_v2_applies_its_own_error_charging_and_refunds(bool $chargeErrors, int $httpStatus, string $message, string $status, int $balance, string $method = 'nin'): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        ServicePrice::factory()->create(['service' => 'nin-v2', 'method' => $method, 'amount' => 20000, 'charge_errors' => $chargeErrors]);
        Http::fake(['https://verify.example.test/api/v1/verify' => Http::response(['status' => false, 'message' => $message], $httpStatus)]);
        $this->actingAs($user);

        Livewire::test(NinVerification::class, ['version' => 'v2'])->set('method', $method)->set($method, $method === 'phone' ? '08165072514' : '01234567890')
            ->set('consent', true)->call('verify')->assertHasErrors(['verification']);
        $record = NinVerificationRecord::query()->sole();
        $this->assertSame($status, $record->status);
        $this->assertSame($balance, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', $status === 'failed' ? 2 : 1);
        Http::assertSentCount(1);
    }

    #[TestWith(['price'])]
    #[TestWith(['balance'])]
    #[TestWith(['disabled'])]
    #[TestWith(['config'])]
    public function test_v2_preconditions_prevent_provider_calls_and_debits(string $condition): void
    {
        $user = User::factory()->create(['wallet_balance' => $condition === 'balance' ? 0 : 100000]);
        if ($condition !== 'price') {
            ServicePrice::factory()->create(['service' => 'nin-v2', 'method' => 'nin', 'amount' => 20000]);
        }
        if ($condition === 'config') {
            config(['services.verify.token' => '']);
        }
        $this->actingAs($user);
        $component = Livewire::test(NinVerification::class, ['version' => 'v2']);
        if ($condition === 'disabled') {
            SiteSetting::query()->updateOrCreate(['key' => 'service_disabled.nin-v2'], ['value' => '1']);
        }

        $component->set('nin', '01234567890')->set('consent', true)->call('verify')
            ->assertHasErrors([$condition === 'disabled' ? 'serviceAvailability' : 'verification']);
        $this->assertDatabaseCount('nin_verification_records', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        Http::assertNothingSent();
    }

    #[TestWith(['08165072514'])]
    #[TestWith(['+234 816 507 2514'])]
    public function test_v2_phone_posts_the_phone_contract_and_uses_phone_pricing(string $phone): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        ServicePrice::factory()->create(['service' => 'nin-v2', 'method' => 'nin', 'amount' => 20000]);
        ServicePrice::factory()->create(['service' => 'nin-v2', 'method' => 'phone', 'amount' => 25000]);
        Http::fake(['https://verify.example.test/api/v1/verify' => Http::response($this->providerResponse())]);
        $this->actingAs($user);

        $component = Livewire::test(NinVerification::class, ['version' => 'v2'])->set('method', 'phone')
            ->set('phone', $phone)->set('consent', true)->call('verify')->assertHasNoErrors();
        $record = NinVerificationRecord::query()->sole();
        $component->assertRedirect(route('nin.v2.result', ['verification' => $record->reference]));
        $this->assertSame('v2', $record->version);
        $this->assertSame('phone', $record->method);
        $this->assertSame('08165072514', $record->phone);
        $this->assertSame('01234567890', $record->nin);
        $this->assertSame('successful', $record->status);
        $this->assertSame($this->providerResponse()['message'], $record->result);
        $this->assertSame(75000, $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', ['id' => $record->wallet_transaction_id, 'service' => 'NIN V2', 'amount' => '250.00']);
        $component->call('verify')->assertHasNoErrors();
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('nin_verification_records', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://verify.example.test/api/v1/verify'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === ['idNumber' => '08165072514', 'type' => 'phone', 'consent' => true]);
        $this->get(route('nin.v2.result', ['verification' => $record->reference]))->assertOk()->assertSee('Taylor');
        $this->get(route('nin.v2'))->assertOk()->assertSee($record->reference)->assertSee('Phone number');
    }

    private function providerResponse(): array
    {
        return ['status' => true, 'cached' => false, 'message' => [
            'idNumber' => '01234567890', 'firstName' => 'Taylor', 'midName' => 'Sample', 'lastName' => 'Example',
            'dateOfBirth' => '01-01-1990', 'gender' => 'M',
            'address' => ['addressLine' => '12 Sample Street', 'lga' => 'Sample LGA', 'state' => 'Sample State'],
        ]];
    }
}
