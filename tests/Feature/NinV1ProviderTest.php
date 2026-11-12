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
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NinV1ProviderTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.verify.url' => 'https://verify.example.test/', 'services.verify.token' => 'test-token']);
    }

    #[TestWith(['nin', 200, 'No record found'])]
    #[TestWith(['phone', 404, 'No record found'])]
    #[TestWith(['nin', 422, 'Invalid NIN details'])]
    #[TestWith(['phone', 200, 'No match found'])]
    #[TestWith(['nin', 200, 'No record found. Please try again.'])]
    public function test_enabled_error_charging_keeps_the_fee_for_provider_rejections(string $method, int $httpStatus, string $message): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => $method, 'amount' => 15000, 'charge_errors' => true]);
        Http::preventStrayRequests();
        Http::fake(['https://verify.example.test/api/'.($method === 'phone' ? 'v1' : 'v3').'/verify' => Http::response(['status' => false, 'message' => $message], $httpStatus)]);
        $this->actingAs($user);
        $identifier = $method === 'phone' ? '07031212750' : '01234567890';

        Livewire::test(NinVerification::class)->set('method', $method)->set($method, $identifier)
            ->set('consent', true)->call('verify')->assertHasErrors(['verification'])->assertSee('verification fee has been charged');

        $record = NinVerificationRecord::query()->sole();
        $this->assertSame('charged_error', $record->status);
        $this->assertTrue($record->charge_errors);
        $this->assertSame(85000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $replayed = app(NinVerificationService::class)->verify($user, $identifier, $record->request_key, $method);
        $this->assertSame($record->id, $replayed->id);
        $this->assertSame(85000, $user->fresh()->wallet_balance);
        Http::assertSentCount(1);
        $this->get(route('nin.v1'))->assertOk()->assertSee('Failed (charged)')->assertDontSee('(refunded)');
    }

    #[TestWith([500, 'No record found'])]
    #[TestWith([503, 'Service unavailable'])]
    #[TestWith([429, 'Rate limit exceeded'])]
    #[TestWith([401, 'Unauthorized'])]
    #[TestWith([403, 'Forbidden'])]
    #[TestWith([200, 'Server error'])]
    #[TestWith([200, 'Insufficient provider balance'])]
    #[TestWith([200, 'Insufficient funds'])]
    #[TestWith([200, 'Service is unavailable'])]
    #[TestWith([200, 'Service not available'])]
    #[TestWith([200, 'Internal error'])]
    #[TestWith([200, 'Provider connection failed'])]
    #[TestWith([200, 'System error'])]
    #[TestWith([200, 'Invalid API token'])]
    #[TestWith([404, 'Endpoint not found'])]
    public function test_infrastructure_errors_are_refunded_even_with_error_charging_enabled(int $httpStatus, string $message): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        ServicePrice::factory()->create(['charge_errors' => true, 'amount' => 15000]);
        Http::preventStrayRequests();
        Http::fake(['https://verify.example.test/api/v3/verify' => Http::response(['status' => false, 'message' => $message], $httpStatus)]);
        $this->actingAs($user);

        Livewire::test(NinVerification::class)->set('nin', '01234567890')->set('consent', true)->call('verify')
            ->assertHasErrors(['verification'])->assertSee('returned to your wallet');

        $record = NinVerificationRecord::query()->sole();
        $this->assertSame('failed', $record->status);
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', ['refund_of_id' => $record->wallet_transaction_id, 'amount' => '150.00']);
        Http::assertSentCount(1);
    }

    public function test_error_charging_uses_the_setting_from_submission_time(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        $price = ServicePrice::factory()->create(['charge_errors' => false, 'amount' => 15000]);
        Http::preventStrayRequests();
        Http::fake(['https://verify.example.test/api/v3/verify' => function () use ($price) {
            $price->update(['charge_errors' => true]);

            return Http::response(['status' => false, 'message' => 'No record found']);
        }]);
        $this->actingAs($user);

        Livewire::test(NinVerification::class)->set('nin', '01234567890')->set('consent', true)->call('verify')
            ->assertHasErrors(['verification'])->assertSee('returned to your wallet');

        $this->assertSame(100000, $user->fresh()->wallet_balance);
        $this->assertSame('failed', NinVerificationRecord::query()->sole()->status);
        Http::assertSentCount(1);
    }

    #[TestWith(['timeout'])]
    #[TestWith(['malformed'])]
    #[TestWith(['invalid-success'])]
    public function test_uncertain_provider_results_are_refunded_with_error_charging_enabled(string $scenario): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        ServicePrice::factory()->create(['charge_errors' => true, 'amount' => 15000]);
        Http::preventStrayRequests();
        $response = match ($scenario) {
            'timeout' => Http::failedConnection(),
            'malformed' => Http::response('<html>Server failed</html>'),
            'invalid-success' => Http::response(['status' => true, 'message' => []]),
        };
        Http::fake(['https://verify.example.test/api/v3/verify' => $response]);
        $this->actingAs($user);

        Livewire::test(NinVerification::class)->set('nin', '01234567890')->set('consent', true)->call('verify')
            ->assertHasErrors(['verification'])->assertSee('returned to your wallet');

        $record = NinVerificationRecord::query()->sole();
        $this->assertSame('failed', $record->status);
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', ['refund_of_id' => $record->wallet_transaction_id]);
        Http::assertSentCount(1);
    }

    public function test_nin_v1_posts_the_contract_saves_private_results_and_charges_once(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'nin', 'amount' => 15000]);
        Http::preventStrayRequests();
        Http::fake(['https://verify.example.test/api/v3/verify' => Http::response($this->providerResponse())]);
        $this->actingAs($user);

        $component = Livewire::test(NinVerification::class)->set('nin', '01234567890')->set('consent', true)->call('verify')->assertHasNoErrors();
        $record = NinVerificationRecord::query()->sole();
        $component->assertRedirect(route('nin.v1.result', ['verification' => $record->reference]));
        $this->assertSame('successful', $record->status);
        $this->assertSame("TEST'PERSON EXAMPLE", $record->fullName());
        $this->assertSame(85000, $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', ['id' => $record->wallet_transaction_id, 'amount' => '150.00', 'service' => 'NIN V1']);
        $this->assertStringNotContainsString('TEST', $record->getRawOriginal('result'));
        $this->assertStringNotContainsString('01234567890', $record->getRawOriginal('nin'));
        $this->assertArrayNotHasKey('result', $record->toArray());
        $component->call('verify')->assertHasNoErrors();
        $this->assertDatabaseCount('nin_verification_records', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://verify.example.test/api/v3/verify'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === ['idNumber' => '01234567890', 'type' => 'nin', 'consent' => true]);
        $this->get(route('nin.v1'))->assertOk()->assertSee($record->reference)->assertSee('Successful');
    }

    #[TestWith(['07031212750'])]
    #[TestWith(['7031212750'])]
    #[TestWith(['2347031212750'])]
    #[TestWith(['+2347031212750'])]
    #[TestWith(['+234 703 121 2750'])]
    #[TestWith(['0703-121-2750'])]
    public function test_phone_verification_uses_v1_and_displays_the_returned_nin(string $phone): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'nin', 'amount' => 15000]);
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'phone', 'amount' => 20000]);
        Http::preventStrayRequests();
        Http::fake(['https://verify.example.test/api/v1/verify' => Http::response($this->providerResponse())]);
        $this->actingAs($user);

        $component = Livewire::test(NinVerification::class)->set('method', 'phone')->set('phone', $phone)
            ->set('consent', true)->call('verify')->assertHasNoErrors();
        $record = NinVerificationRecord::query()->sole();
        $component->assertRedirect(route('nin.v1.result', ['verification' => $record->reference]));
        $this->assertSame('successful', $record->status);
        $this->assertSame('phone', $record->method);
        $this->assertSame('07031212750', $record->phone);
        $this->assertSame('01234567890', $record->nin);
        $this->assertSame(80000, $user->fresh()->wallet_balance);
        $this->assertStringNotContainsString('07031212750', $record->getRawOriginal('phone'));
        $this->assertArrayNotHasKey('phone', $record->toArray());
        $component->call('verify')->assertHasNoErrors();
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://verify.example.test/api/v1/verify'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === ['idNumber' => '07031212750', 'type' => 'phone', 'consent' => true]);
        $this->get(route('nin.v1.result', ['verification' => $record->reference]))->assertOk()
            ->assertSee('01234567890')->assertSee('Download slips')->assertSee('Personal information');
        $this->get(route('nin.v1'))->assertOk()->assertSee('Phone number')->assertSee($record->reference);
    }

    #[TestWith(['rejected', 'phone'])]
    #[TestWith(['timeout', 'phone'])]
    #[TestWith(['malformed', 'phone'])]
    #[TestWith(['rejected'])]
    #[TestWith(['server-error'])]
    #[TestWith(['html'])]
    #[TestWith(['malformed'])]
    #[TestWith(['mismatched-nin'])]
    #[TestWith(['timeout'])]
    public function test_unsuccessful_or_invalid_provider_responses_refund_the_fee(string $scenario, string $method = 'nin'): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => $method, 'amount' => 15000]);
        $response = match ($scenario) {
            'rejected' => Http::response(['status' => false, 'message' => 'No match']),
            'server-error' => Http::response(['message' => 'Error'], 500),
            'html' => Http::response('<html>Error</html>', 200, ['Content-Type' => 'text/html']),
            'malformed' => Http::response(['status' => true, 'message' => []]),
            'mismatched-nin' => Http::response(['status' => true, 'message' => ['idNumber' => '11111111111', 'firstName' => 'Wrong', 'lastName' => 'Person']]),
            'timeout' => Http::failedConnection(),
        };
        Http::preventStrayRequests();
        Http::fake(['https://verify.example.test/api/'.($method === 'phone' ? 'v1' : 'v3').'/verify' => $response]);
        $this->actingAs($user);

        Livewire::test(NinVerification::class)->set('method', $method)->set($method, $method === 'phone' ? '07031212750' : '01234567890')->set('consent', true)->call('verify')
            ->assertHasErrors(['verification'])->assertSee('returned to your wallet');

        $record = NinVerificationRecord::query()->sole();
        $this->assertSame('failed', $record->status);
        $this->assertNull($record->result);
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', ['refund_of_id' => $record->wallet_transaction_id, 'amount' => '150.00']);
        $this->get(route('nin.v1.result', ['verification' => $record->reference]))->assertNotFound();
        Http::assertSentCount(1);
    }

    #[TestWith(['missing-config'])]
    #[TestWith(['missing-price'])]
    #[TestWith(['insufficient-balance'])]
    #[TestWith(['disabled'])]
    public function test_ineligible_submissions_do_not_call_the_provider_or_debit_wallets(string $scenario): void
    {
        $user = User::factory()->create(['wallet_balance' => 100]);
        if ($scenario !== 'missing-price') {
            ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'nin', 'amount' => 1000]);
        }
        if ($scenario === 'missing-config') {
            config(['services.verify.token' => '']);
        }
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs($user);
        $component = Livewire::test(NinVerification::class)->set('nin', '01234567890')->set('consent', true);
        if ($scenario === 'disabled') {
            SiteSetting::query()->updateOrCreate(['key' => 'service_disabled.nin-v1'], ['value' => '1']);
        }

        $component->call('verify')->assertHasErrors([$scenario === 'disabled' ? 'serviceAvailability' : 'verification']);

        $this->assertSame(100, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('nin_verification_records', 0);
        Http::assertNothingSent();
    }

    public function test_a_request_key_cannot_be_reused_for_another_nin(): void
    {
        $user = User::factory()->create();
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'nin', 'amount' => 0]);
        Http::preventStrayRequests();
        Http::fake(['https://verify.example.test/api/v3/verify' => Http::response($this->providerResponse())]);
        $this->actingAs($user);
        $component = Livewire::test(NinVerification::class)->set('nin', '01234567890')->set('consent', true)->call('verify')->assertHasNoErrors();

        $component->set('nin', '11111111111')->call('verify')->assertHasErrors(['verification']);

        Http::assertSentCount(1);
        $this->assertDatabaseCount('nin_verification_records', 1);
    }

    public function test_result_page_shows_images_slips_and_all_nested_message_fields_with_escaped_output(): void
    {
        $user = User::factory()->create();
        $record = NinVerificationRecord::factory()->for($user)->create(['result' => $this->providerResponse()['message']]);
        $this->actingAs($user);

        $this->get(route('nin.v1.result', ['verification' => $record->reference]))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSeeInOrder(['Signature', 'Download slips', 'Personal information'])
            ->assertSee("TEST'PERSON EXAMPLE")->assertSee('Sample State')->assertSee('Sample Street')
            ->assertSee('Religion')->assertSee('—')->assertSee('<script>alert(1)</script>')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('alt="Photo of', false)->assertSee('alt="Signature of', false)
            ->assertSee('regular.png')->assertSee('premium.png')->assertSee('standard.png')->assertSee('ninDig.jpg');
    }

    #[TestWith(['regular'])]
    #[TestWith(['premium'])]
    #[TestWith(['standard'])]
    #[TestWith(['digital'])]
    public function test_slip_downloads_are_personalized_pdfs_accessible_only_to_the_owner(string $slip): void
    {
        $user = User::factory()->create();
        $record = NinVerificationRecord::factory()->for($user)->create(['result' => $this->providerResponse()['message']]);
        $this->actingAs($user);
        $url = route('nin.v1.slip', ['verification' => $record->reference, 'slip' => $slip]);

        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('nin-'.$slip.'-'.$record->reference.'.pdf');
        $this->assertStringStartsWith('%PDF-1.4', $response->getContent());
        $this->assertStringEndsWith("%%EOF\n", $response->getContent());
        if (getenv('NIN_SLIP_QA_DIR')) {
            file_put_contents(getenv('NIN_SLIP_QA_DIR').'/'.$slip.'.pdf', $response->getContent());
        }

        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }

    public function test_results_reject_guests_other_users_and_unknown_slips(): void
    {
        $record = NinVerificationRecord::factory()->create();
        $url = route('nin.v1.result', ['verification' => $record->reference]);
        $this->get($url)->assertRedirect(route('home'));
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs($record->user)->get(route('nin.v1.slip', ['verification' => $record->reference, 'slip' => 'unknown']))->assertNotFound();
        $this->actingAs(User::factory()->suspended()->create())->get($url)->assertRedirect(route('home'));
    }

    public function test_unsafe_photo_sources_are_not_rendered(): void
    {
        $record = NinVerificationRecord::factory()->create(['result' => ['firstName' => 'Test', 'lastName' => 'Person', 'photo' => 'https://evil.example.test/photo', 'signature' => 'data:image/svg+xml;base64,'.base64_encode('<svg onload="alert(1)"/>')]]);
        $this->actingAs($record->user);

        $this->get(route('nin.v1.result', ['verification' => $record->reference]))->assertOk()
            ->assertSee('Photo unavailable')->assertSee('Signature unavailable')->assertDontSee('evil.example.test');
    }

    /** @return array{status: bool, message: array<string, mixed>, transID: string} */
    private function providerResponse(): array
    {
        $canvas = imagecreatetruecolor(120, 160);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 120, 150, 180));
        ob_start();
        imagepng($canvas);
        $image = 'data:image/png;base64,'.base64_encode(ob_get_clean());

        return ['status' => true, 'message' => [
            'firstName' => "TEST'PERSON", 'middleName' => ' ', 'lastName' => 'EXAMPLE',
            'idNumber' => '01234567890', 'mobile' => '08012345678', 'dateOfBirth' => '20-01-1995', 'gender' => 'M',
            'address' => ['state' => 'Sample State', 'town' => 'Sample Town', 'addressLine' => 'Sample Street', 'lga' => 'Sample LGA'],
            'birthCountry' => 'nigeria', 'birthState' => ' ', 'trackingId' => ' ', 'religion' => 'Sample religion',
            'photo' => $image, 'signature' => $image, 'nok_firstname' => '<script>alert(1)</script>',
        ], 'transID' => 'provider-reference'];
    }
}
