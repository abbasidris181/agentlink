<?php

namespace Tests\Feature;

use App\Livewire\NinVerification;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NinVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['v1'])]
    #[TestWith(['v2'])]
    #[TestWith(['v3'])]
    public function test_each_nin_page_requires_sign_in_and_displays_its_form(string $version): void
    {
        $this->get(route('nin.'.$version))->assertRedirect(route('home'));
        $this->actingAs(User::factory()->create());

        $this->get(route('nin.'.$version))->assertOk()
            ->assertViewIs('nin-'.$version)
            ->assertSeeLivewire(NinVerification::class)
            ->assertSee('I consent to the use of the details provided for this NIN verification.')
            ->assertSee('type="checkbox"', false)
            ->assertSee('NIN-'.strtoupper($version).' verification')
            ->assertSee('Verification method')->assertSee('DEMOGRAPHICS')->assertSee('Verify')
            ->assertSee('href="'.route('nin.'.$version).'"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('NIN-'.strtoupper($version).' verification history')
            ->assertSeeInOrder(['Verification history', 'Transaction id', 'Method', 'Status', 'Date', 'Price', 'No verifications yet']);
    }

    public function test_unknown_versions_are_not_found(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/nin/v4')->assertNotFound();
        Livewire::test(NinVerification::class, ['version' => 'v4'])->assertNotFound();
    }

    public function test_guests_cannot_use_the_form_component(): void
    {
        Livewire::test(NinVerification::class)->assertForbidden();
    }

    public function test_a_valid_eleven_digit_nin_preserves_leading_zero_and_opens_the_result(): void
    {
        $this->actingAs(User::factory()->create());

        config(['services.verify.url' => 'https://verify.example.test', 'services.verify.token' => 'test-token']);
        ServicePrice::factory()->create(['service' => 'nin-v3', 'method' => 'nin', 'amount' => 0]);
        Http::preventStrayRequests();
        Http::fake(['https://verify.example.test/api/v2/verify' => Http::response(['status' => true, 'message' => ['idNumber' => '01234567890', 'firstName' => 'Taylor', 'lastName' => 'Example']])]);

        Livewire::test(NinVerification::class, ['version' => 'v3'])->set('nin', '01234567890')->set('consent', true)->call('verify')
            ->assertHasNoErrors()->assertSet('nin', '01234567890')->assertSet('showAvailability', false)->assertRedirect();
        Http::assertSentCount(1);
    }

    #[TestWith([''])]
    #[TestWith(['1234567890'])]
    #[TestWith(['123456789012'])]
    #[TestWith(['1234567890a'])]
    public function test_invalid_nin_numbers_are_rejected(string $nin): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(NinVerification::class)->set('nin', $nin)->set('consent', true)->call('verify')
            ->assertHasErrors(['nin'])->assertSet('showAvailability', false);
    }

    #[TestWith(['08012345678', '08012345678'])]
    #[TestWith(['8012345678', '8012345678'])]
    #[TestWith(['2348012345678', '2348012345678'])]
    #[TestWith(['+2348012345678', '+2348012345678'])]
    #[TestWith(['+234 801 234 5678', '+2348012345678'])]
    #[TestWith(['0801-234-5678', '08012345678'])]
    public function test_phone_numbers_accept_local_and_country_code_formats(string $phone, string $normalized): void
    {
        $this->actingAs(User::factory()->create());

        config(['services.verify.url' => 'https://verify.example.test', 'services.verify.token' => 'test-token']);
        ServicePrice::factory()->create(['service' => 'nin-v2', 'method' => 'phone', 'amount' => 0]);
        Http::preventStrayRequests();
        Http::fake(['https://verify.example.test/api/v1/verify' => Http::response(['status' => true, 'message' => ['idNumber' => '01234567890', 'firstName' => 'Taylor', 'lastName' => 'Example']])]);

        Livewire::test(NinVerification::class, ['version' => 'v2'])->set('method', 'phone')->set('phone', $phone)
            ->set('consent', true)->call('verify')->assertHasNoErrors()->assertSet('phone', $normalized)
            ->assertSet('showAvailability', false)->assertRedirect();
        Http::assertSentCount(1);
    }

    #[TestWith([''])]
    #[TestWith(['12345'])]
    #[TestWith(['+23408012345678'])]
    #[TestWith(['0801234567a'])]
    #[TestWith(['+18012345678'])]
    public function test_invalid_phone_numbers_are_rejected(string $phone): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(NinVerification::class)->set('method', 'phone')->set('phone', $phone)
            ->set('consent', true)->call('verify')->assertHasErrors(['phone'])->assertSet('showAvailability', false);
    }

    #[TestWith([''])]
    #[TestWith(['Middle'])]
    public function test_demographics_accepts_an_optional_middle_name(string $middleName): void
    {
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'demographics', 'amount' => 0]);
        $this->actingAs(User::factory()->create());

        Livewire::test(NinVerification::class)->set('method', 'demographics')
            ->set('first_name', 'Taylor')->set('surname', 'Example')->set('middle_name', $middleName)
            ->set('dob', '1995-01-20')->set('gender', 'female')->set('consent', true)->call('verify')
            ->assertHasNoErrors()->assertSet('showAvailability', true);
    }

    public function test_demographic_fields_are_required_except_middle_name(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(NinVerification::class)->set('method', 'demographics')->set('consent', true)->call('verify')
            ->assertHasErrors(['first_name' => 'required', 'surname' => 'required', 'dob' => 'required', 'gender' => 'required'])
            ->assertHasNoErrors(['middle_name'])->assertSet('showAvailability', false);
    }

    #[DataProvider('invalidDemographics')]
    public function test_invalid_demographic_details_are_rejected(string $field, string $value, string $rule): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(NinVerification::class)->set('method', 'demographics')
            ->set('first_name', 'Taylor')->set('surname', 'Example')
            ->set('dob', '1995-01-20')->set('gender', 'male')->set($field, $value)->set('consent', true)->call('verify')
            ->assertHasErrors([$field => $rule])->assertSet('showAvailability', false);
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidDemographics(): array
    {
        return [
            'blank first name' => ['first_name', '   ', 'required'],
            'blank surname' => ['surname', '   ', 'required'],
            'long first name' => ['first_name', str_repeat('a', 101), 'max'],
            'long surname' => ['surname', str_repeat('a', 101), 'max'],
            'long middle name' => ['middle_name', str_repeat('a', 101), 'max'],
            'invalid date' => ['dob', '2025-02-30', 'date_format'],
            'future date' => ['dob', '2999-01-01', 'before_or_equal'],
            'invalid gender' => ['gender', 'invalid', 'in'],
        ];
    }

    public function test_changing_method_clears_old_values_errors_and_availability(): void
    {
        ServicePrice::factory()->create(['service' => 'nin-v2', 'method' => 'demographics', 'amount' => 0]);
        $this->actingAs(User::factory()->create());

        Livewire::test(NinVerification::class, ['version' => 'v2'])->set('method', 'demographics')
            ->set('first_name', 'Taylor')->set('surname', 'Example')->set('dob', '1990-01-01')->set('gender', 'male')
            ->set('consent', true)->call('verify')->assertHasNoErrors()->assertSet('showAvailability', true)
            ->set('method', 'nin')->assertSet('first_name', '')->assertSet('consent', false)->assertSet('showAvailability', false)
            ->set('nin', 'invalid')->set('consent', true)->call('verify')->assertHasErrors(['nin'])
            ->set('method', 'phone')->assertSet('nin', '')->assertHasNoErrors()
            ->assertSee('Phone number')->assertDontSee('Date of birth');
    }

    public function test_unknown_verification_methods_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(NinVerification::class)->set('method', 'invalid')->set('consent', true)->call('verify')
            ->assertHasErrors(['method' => 'in'])->assertSet('showAvailability', false);
    }

    #[TestWith(['v1', 'nin'])]
    #[TestWith(['v1', 'phone'])]
    #[TestWith(['v1', 'demographics'])]
    #[TestWith(['v2', 'nin'])]
    #[TestWith(['v2', 'phone'])]
    #[TestWith(['v2', 'demographics'])]
    #[TestWith(['v3', 'nin'])]
    #[TestWith(['v3', 'phone'])]
    #[TestWith(['v3', 'demographics'])]
    public function test_consent_is_required_for_each_nin_service_and_method(string $version, string $method): void
    {
        ServicePrice::factory()->create(['service' => 'nin-'.$version, 'method' => 'demographics', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        config(['services.verify.url' => 'https://verify.example.test', 'services.verify.token' => 'test-token']);
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'nin', 'amount' => 0]);
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'phone', 'amount' => 0]);
        ServicePrice::factory()->create(['service' => 'nin-v2', 'method' => 'nin', 'amount' => 0]);
        ServicePrice::factory()->create(['service' => 'nin-v2', 'method' => 'phone', 'amount' => 0]);
        ServicePrice::factory()->create(['service' => 'nin-v3', 'method' => 'nin', 'amount' => 0]);
        ServicePrice::factory()->create(['service' => 'nin-v3', 'method' => 'phone', 'amount' => 0]);
        Http::preventStrayRequests();
        Http::fake([
            'https://verify.example.test/api/v2/verify' => Http::response(['status' => true, 'message' => ['firstName' => 'Taylor', 'lastName' => 'Example', 'idNumber' => '01234567890']]),
            'https://verify.example.test/api/v3/verify' => Http::response(['status' => true, 'message' => ['firstName' => 'Taylor', 'lastName' => 'Example', 'idNumber' => '01234567890']]),
            'https://verify.example.test/api/v1/verify' => Http::response(['status' => true, 'message' => ['firstName' => 'Taylor', 'lastName' => 'Example', 'idNumber' => '01234567890']]),
        ]);

        Livewire::test(NinVerification::class, ['version' => $version])
            ->set('method', $method)->set('nin', '01234567890')->set('phone', '08012345678')
            ->set('first_name', 'Taylor')->set('surname', 'Example')
            ->set('dob', '1995-01-20')->set('gender', 'female')
            ->assertSet('consent', false)->call('verify')
            ->assertHasErrors(['consent' => 'accepted'])->assertSet('showAvailability', false)
            ->assertSee('Please give your consent before continuing with verification.')
            ->set('consent', true)->call('verify')->assertHasNoErrors()
            ->assertSet('showAvailability', ! (in_array($method, ['nin', 'phone'], true)))
            ->set('consent', false)->call('verify')
            ->assertHasErrors(['consent' => 'accepted'])->assertSet('showAvailability', false);
        Http::assertSentCount(in_array($method, ['nin', 'phone'], true) ? 1 : 0);
    }

    public function test_verification_is_blocked_after_sign_out(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(NinVerification::class);
        Auth::logout();

        $component->call('verify')->assertForbidden();
    }
}
