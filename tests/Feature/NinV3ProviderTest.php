<?php

namespace Tests\Feature;

use App\Livewire\NinVerification;
use App\Models\NinVerificationRecord;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NinV3ProviderTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.verify.url' => 'https://verify.example.test/', 'services.verify.token' => 'test-token']);
        Http::preventStrayRequests();
    }

    #[TestWith(['nin', '01234567890', 'nin_basic', '01234567890', 23000])]
    #[TestWith(['phone', '08165072514', 'phone', '08165072514', 27000])]
    #[TestWith(['phone', '+234 816 507 2514', 'phone', '08165072514', 27000])]
    public function test_v3_posts_the_correct_provider_type_and_charges_its_method_price_once(string $method, string $input, string $type, string $identifier, int $price): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        ServicePrice::factory()->create(['service' => 'nin-v3', 'method' => 'nin', 'amount' => 23000]);
        ServicePrice::factory()->create(['service' => 'nin-v3', 'method' => 'phone', 'amount' => 27000]);
        Http::fake(['https://verify.example.test/api/v2/verify' => Http::response($this->providerResponse())]);
        $this->actingAs($user);

        $component = Livewire::test(NinVerification::class, ['version' => 'v3'])->set('method', $method)
            ->set($method, $input)->set('consent', true)->call('verify')->assertHasNoErrors();
        $record = NinVerificationRecord::query()->sole();
        $component->assertRedirect(route('nin.v3.result', ['verification' => $record->reference]));
        $this->assertSame('v3', $record->version);
        $this->assertSame($method, $record->method);
        $this->assertSame('successful', $record->status);
        $this->assertSame('01234567890', $record->nin);
        $this->assertSame($method === 'phone' ? $identifier : null, $record->phone);
        $this->assertSame($this->providerResponse()['message'], $record->result);
        $this->assertSame(100000 - $price, $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', ['id' => $record->wallet_transaction_id, 'service' => 'NIN V3']);
        $this->assertStringContainsString('Taylor', $record->getRawOriginal('result'));
        $component->call('verify')->assertHasNoErrors();
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('nin_verification_records', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://verify.example.test/api/v2/verify'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === ['idNumber' => $identifier, 'type' => $type, 'consent' => true]);
        $this->get(route('nin.v3'))->assertOk()->assertSee($record->reference)
            ->assertSee(route('nin.v3.result', ['verification' => $record->reference]), false);
        $this->get(route('nin.v1'))->assertOk()->assertDontSee($record->reference);
        $this->get(route('nin.v2'))->assertOk()->assertDontSee($record->reference);
    }

    #[TestWith(['regular'])]
    #[TestWith(['standard'])]
    #[TestWith(['premium'])]
    #[TestWith(['digital'])]
    public function test_v3_results_and_slips_are_private_and_use_v3_routes(string $slip): void
    {
        $record = NinVerificationRecord::factory()->create(['version' => 'v3', 'result' => $this->providerResponse()['message']]);
        $result = route('nin.v3.result', ['verification' => $record->reference]);
        $download = route('nin.v3.slip', ['verification' => $record->reference, 'slip' => $slip]);
        $this->get($result)->assertRedirect();
        $this->actingAs(User::factory()->create())->get($result)->assertNotFound();
        $this->get($download)->assertNotFound();
        $this->actingAs($record->user)->get($result)->assertOk()->assertSee('NIN V3')->assertSee('Taylor')
            ->assertSee(route('nin.v3'))->assertSee($download, false);
        $this->get($download)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('nin.v1.result', ['verification' => $record->reference]))->assertNotFound();
        $this->get(route('nin.v2.result', ['verification' => $record->reference]))->assertNotFound();
    }

    #[TestWith(['nin', true, 200, 'No record found', 'charged_error', 77000])]
    #[TestWith(['phone', true, 200, 'No record found', 'charged_error', 77000])]
    #[TestWith(['nin', false, 200, 'No record found', 'failed', 100000])]
    #[TestWith(['phone', true, 503, 'Service unavailable', 'failed', 100000])]
    #[TestWith(['nin', true, 200, 'Insufficient provider balance', 'failed', 100000])]
    public function test_v3_applies_error_charges_and_refunds(string $method, bool $chargeErrors, int $httpStatus, string $message, string $status, int $balance): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        ServicePrice::factory()->create(['service' => 'nin-v3', 'method' => $method, 'amount' => 23000, 'charge_errors' => $chargeErrors]);
        Http::fake(['https://verify.example.test/api/v2/verify' => Http::response(['status' => false, 'message' => $message], $httpStatus)]);
        $this->actingAs($user);

        Livewire::test(NinVerification::class, ['version' => 'v3'])->set('method', $method)
            ->set($method, $method === 'nin' ? '01234567890' : '08165072514')->set('consent', true)->call('verify')
            ->assertHasErrors(['verification']);
        $record = NinVerificationRecord::query()->sole();
        $this->assertSame($status, $record->status);
        $this->assertSame($balance, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', $status === 'failed' ? 2 : 1);
        Http::assertSentCount(1);
    }

    private function providerResponse(): array
    {
        return ['status' => true, 'message' => [
            'idNumber' => '01234567890', 'firstName' => 'Taylor', 'midName' => 'Sample', 'lastName' => 'Example',
            'dateOfBirth' => '01-01-1990', 'gender' => 'M',
            'address' => ['addressLine' => '12 Sample Street', 'lga' => 'Sample LGA', 'state' => 'Sample State'],
        ]];
    }
}
