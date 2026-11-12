<?php

namespace Tests\Feature;

use App\BvnLicenseOnboardingInput;
use App\Livewire\Admin\Prices;
use App\Livewire\BvnLicenseOnboarding;
use App\Livewire\Dashboard;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\Models\SiteSetting;
use App\Models\User;
use App\NigeriaLocations;
use App\ServiceAvailability;
use App\SubmitServiceRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BvnLicenseOnboardingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authenticated_users_can_open_the_service_from_the_grid_and_sidebar(): void
    {
        $this->get(route('bvn.onboarding'))->assertRedirect(route('home'));
        Livewire::test(BvnLicenseOnboarding::class)->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk()->assertSee('BVN LICENSE ONBOARDING')->assertSee('href="'.route('bvn.onboarding').'"', false);
        Livewire::test(Dashboard::class)->call('selectService', 'bvn-license-onboarding')->assertRedirect(route('bvn.onboarding'));
        $page = $this->get(route('bvn.onboarding'))->assertOk()->assertSeeLivewire(BvnLicenseOnboarding::class)
            ->assertSee('Android BVN enrollment user requirements')->assertSee('aria-current="page"', false);
        foreach (BvnLicenseOnboardingInput::FIELDS as $label) {
            $page->assertSee($label);
        }
    }

    public function test_location_options_cover_all_states_fct_and_774_lgas(): void
    {
        $states = NigeriaLocations::states();
        $this->assertCount(37, $states);
        $count = 0;
        foreach ($states as $state => $label) {
            $lgas = NigeriaLocations::lgas($state);
            $this->assertNotEmpty($lgas);
            $this->assertSame(count($lgas), count(array_unique($lgas)));
            $this->assertContains(NigeriaLocations::region($state), ['North Central', 'North East', 'North West', 'South East', 'South South', 'South West']);
            $count += count($lgas);
        }
        $this->assertSame(774, $count);
        $this->assertCount(44, NigeriaLocations::lgas('Kano'));
        $this->assertCount(20, NigeriaLocations::lgas('Lagos'));
        $this->assertCount(6, NigeriaLocations::lgas('FCT'));
        $this->assertContains('Bwari', NigeriaLocations::lgas('FCT'));
        $this->assertSame('North Central', NigeriaLocations::region('FCT'));
    }

    #[TestWith(['Lagos', 'South West'])]
    #[TestWith(['Kano', 'North West'])]
    #[TestWith(['Adamawa', 'North East'])]
    #[TestWith(['Abia', 'South East'])]
    #[TestWith(['Rivers', 'South South'])]
    #[TestWith(['FCT', 'North Central'])]
    public function test_region_is_derived_from_the_selected_state(string $state, string $region): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(BvnLicenseOnboarding::class)->set('details.state', $state)->assertSet('region', $region)
            ->assertViewHas('lgas', NigeriaLocations::lgas($state))->assertSee('readonly', false);
    }

    public function test_changing_states_clears_the_previous_lga_and_preserves_agent_details(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(BvnLicenseOnboarding::class)->set('details.first_name', 'Taylor')
            ->set('details.state', 'Lagos')->set('details.lga', 'Ikeja')
            ->set('details.state', 'FCT')->assertSet('details.lga', '')->assertSet('details.first_name', 'Taylor')
            ->assertSet('region', 'North Central')->assertSee('Bwari')->assertDontSee('<option value="Ikeja">', false);
    }

    public function test_submission_saves_all_inputs_privately_charges_once_and_is_available_for_admin_review(): void
    {
        $user = User::factory()->create(['wallet_balance' => 50000]);
        ServicePrice::factory()->create(['service' => 'bvn-license-onboarding', 'method' => 'enrollment', 'amount' => 12500]);
        $this->actingAs($user);
        Http::preventStrayRequests();
        $form = Livewire::test(BvnLicenseOnboarding::class)->set('details', $this->details())->call('submit')
            ->assertHasNoErrors()->assertSee('Submission successful.');
        $record = ServiceRequest::query()->sole();
        $this->assertSame('bvn-license-onboarding', $record->service);
        $this->assertSame('enrollment', $record->method);
        $this->assertSame('pending', $record->status);
        $this->assertSame([...$this->details(), 'region' => 'South West'], $record->payload);
        $this->assertSame('0012345678', $record->payload['account_number']);
        $this->assertSame('01234567890', $record->payload['bvn']);
        $this->assertSame(12500, $record->quoted_price);
        $this->assertSame(37500, $user->fresh()->wallet_balance);
        $this->assertStringNotContainsString('0012345678', $record->getRawOriginal('payload'));
        $this->assertStringNotContainsString('taylor@example.test', $record->getRawOriginal('payload'));
        $form->call('submit')->assertHasNoErrors();
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertNothingSent();
        $this->actingAs(User::factory()->administrator()->create());
        $this->get(route('admin.requests.service', ['service' => 'bvn-license-onboarding']))->assertOk()
            ->assertSee($record->reference)->assertSee('0012345678')->assertSee('South West')
            ->assertSee('data-copy-label="Bank name" data-copy-value="Example Bank"', false);
        $this->get(route('admin.requests.show', ['requestId' => $record->id]))->assertOk()->assertSee('Ikeja')->assertSee('Taylor');
    }

    public function test_other_users_cannot_see_an_agents_request_history(): void
    {
        $record = ServiceRequest::factory()->create(['service' => 'bvn-license-onboarding', 'method' => 'enrollment', 'payload' => [...$this->details(), 'region' => 'South West']]);
        $this->actingAs(User::factory()->create());
        Livewire::test(BvnLicenseOnboarding::class)->assertDontSee($record->reference)->assertDontSee('taylor@example.test');
    }

    public function test_required_agent_fields_are_validated(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(BvnLicenseOnboarding::class)->call('submit')
            ->assertHasErrors(array_map(fn (string $field): string => 'details.'.$field, array_keys(BvnLicenseOnboardingInput::FIELDS)));
        $this->assertDatabaseCount('service_requests', 0);
    }

    #[TestWith(['email', 'invalid'])]
    #[TestWith(['bvn', '1234567890'])]
    #[TestWith(['account_number', '123456789'])]
    #[TestWith(['phone', 'invalid'])]
    #[TestWith(['dob', '2999-01-01'])]
    #[TestWith(['state', 'Unknown state'])]
    #[TestWith(['lga', 'Bwari'])]
    #[TestWith(['last_name', ''])]
    public function test_invalid_input_and_cross_state_lgas_cannot_be_submitted(string $field, string $value): void
    {
        $this->actingAs(User::factory()->create());
        $details = [...$this->details(), $field => $value];
        Livewire::test(BvnLicenseOnboarding::class)->set('details', $details)->call('submit')->assertHasErrors('details.'.$field);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_client_cannot_override_the_state_region(): void
    {
        $this->actingAs(User::factory()->create());
        ServicePrice::factory()->create(['service' => 'bvn-license-onboarding', 'method' => 'enrollment', 'amount' => 0]);
        Livewire::test(BvnLicenseOnboarding::class)->set('details', [...$this->details(), 'region' => 'North East'])
            ->call('submit')->assertHasNoErrors();
        $this->assertSame('South West', ServiceRequest::query()->sole()->payload['region']);
    }

    public function test_submission_action_rejects_a_forged_region_at_the_service_boundary(): void
    {
        $user = User::factory()->create();
        try {
            app(SubmitServiceRequest::class)->create($user, 'bvn-license-onboarding', 'enrollment', [...$this->details(), 'region' => 'North East'], (string) Str::uuid());
            $this->fail('A region that differs from the selected state must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('input.region', $exception->errors());
        }
        $this->assertDatabaseCount('service_requests', 0);
    }

    #[TestWith([null, 50000])]
    #[TestWith([60000, 50000])]
    public function test_unset_prices_or_insufficient_balance_cannot_create_requests(?int $price, int $balance): void
    {
        if ($price !== null) {
            ServicePrice::factory()->create(['service' => 'bvn-license-onboarding', 'method' => 'enrollment', 'amount' => $price]);
        }
        $user = User::factory()->create(['wallet_balance' => $balance]);
        $this->actingAs($user);
        Livewire::test(BvnLicenseOnboarding::class)->set('details', $this->details())->call('submit')->assertHasErrors('serviceAvailability');
        $this->assertSame($balance, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_pricing_and_unavailable_controls_integrate_with_existing_history(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Prices::class)->set('prices.bvn-license-onboarding.enrollment', '125')
            ->set('unavailableTypes.bvn-license-onboarding.enrollment', true)->call('save')->assertHasNoErrors();
        $this->assertSame(12500, ServicePrice::amountFor('bvn-license-onboarding', 'enrollment'));
        $record = ServiceRequest::factory()->create(['service' => 'bvn-license-onboarding', 'method' => 'enrollment', 'payload' => [...$this->details(), 'region' => 'South West']]);
        $this->actingAs($record->user);
        $this->get(route('dashboard'))->assertOk()->assertSee('href="'.route('bvn.onboarding').'"', false);
        Livewire::test(BvnLicenseOnboarding::class)->assertSee($record->reference)->assertViewHas('canSubmit', false)
            ->call('submit')->assertHasErrors('serviceAvailability');
        SiteSetting::create(['key' => ServiceAvailability::settingKey('bvn-license-onboarding'), 'value' => '1']);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('BVN LICENSE ONBOARDING');
        $this->get(route('bvn.onboarding'))->assertRedirect(route('dashboard'));
    }

    public function test_inactive_users_cannot_submit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Livewire::test(BvnLicenseOnboarding::class)->set('details', $this->details());
        $user->forceFill(['is_active' => false])->save();
        $form->call('submit')->assertForbidden();
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_signed_out_users_cannot_submit_an_open_form(): void
    {
        $this->actingAs(User::factory()->create());
        $form = Livewire::test(BvnLicenseOnboarding::class)->set('details', $this->details());
        Auth::logout();
        $form->call('submit')->assertForbidden();
        $this->assertDatabaseCount('service_requests', 0);
    }

    private function details(): array
    {
        return [
            'last_name' => 'Example', 'first_name' => 'Taylor', 'middle_name' => 'Alex',
            'dob' => '1990-01-01', 'email' => 'taylor@example.test', 'account_name' => 'Taylor Alex Example',
            'bank_name' => 'Example Bank', 'account_number' => '0012345678', 'bvn' => '01234567890',
            'address' => '12 Example Street', 'phone' => '08165072514', 'city' => 'Lagos',
            'location' => 'Example location', 'state' => 'Lagos', 'lga' => 'Ikeja',
        ];
    }
}
