<?php

namespace Tests\Feature;

use App\Livewire\Admin\Prices;
use App\Livewire\Dashboard;
use App\Livewire\DemographicsSearch;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\Models\SiteSetting;
use App\Models\User;
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

class DemographicsSearchTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_service_requires_authentication_and_opens_from_dashboard(): void
    {
        $this->get(route('demographics.search'))->assertRedirect(route('home'));
        Livewire::test(DemographicsSearch::class)->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk()->assertSee('DEMOGRAPHICS SEARCH')
            ->assertSee('href="'.route('demographics.search').'"', false);
        Livewire::test(Dashboard::class)->call('selectService', 'demographics-search')->assertRedirect(route('demographics.search'));
        $this->get(route('demographics.search'))->assertOk()->assertSeeLivewire(DemographicsSearch::class)
            ->assertSee('First name')->assertSee('Last name')->assertSee('Middle name (optional)')
            ->assertSee('placeholder="DD-MM-YYYY"', false)
            ->assertSee('<option value="Male">Male</option>', false)
            ->assertSee('<option value="Female">Female</option>', false);
    }

    #[TestWith(['', 'Male'])]
    #[TestWith(['Alex', 'Female'])]
    public function test_valid_requests_save_private_details_and_charge_once(string $middleName, string $gender): void
    {
        $user = User::factory()->create(['wallet_balance' => 50000]);
        ServicePrice::factory()->create(['service' => 'demographics-search', 'method' => 'search', 'amount' => 12000]);
        $this->actingAs($user);
        Http::preventStrayRequests();
        $details = [...$this->details(), 'middle_name' => $middleName, 'gender' => $gender];
        $form = Livewire::test(DemographicsSearch::class)->set('details', [...$details, 'first_name' => ' Taylor '])
            ->call('submit')->assertHasNoErrors()->assertSee('saved for admin review');
        $record = ServiceRequest::query()->sole();
        $this->assertSame('demographics-search', $record->service);
        $this->assertSame('search', $record->method);
        $this->assertSame('pending', $record->status);
        $this->assertSame($details, $record->payload);
        $this->assertSame(12000, $record->quoted_price);
        $this->assertSame(38000, $user->fresh()->wallet_balance);
        $this->assertStringNotContainsString('Taylor', $record->getRawOriginal('payload'));
        $this->assertArrayNotHasKey('payload', $record->toArray());
        $form->call('submit')->assertHasNoErrors();
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertNothingSent();
        $this->actingAs(User::factory()->administrator()->create());
        $this->get(route('admin.requests.service', ['service' => 'demographics-search']))->assertOk()
            ->assertSee($record->reference)->assertSee('16-05-1990')
            ->assertSee('data-copy-label="Last name" data-copy-value="Example"', false);
        $this->get(route('admin.requests.show', ['requestId' => $record->id]))->assertOk()->assertSee('Taylor')->assertSee($gender);
    }

    public function test_middle_name_can_be_omitted_and_leap_day_is_valid(): void
    {
        ServicePrice::factory()->create(['service' => 'demographics-search', 'method' => 'search', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        $details = $this->details();
        unset($details['middle_name']);
        $details['dob'] = '29-02-2000';
        Livewire::test(DemographicsSearch::class)->set('details', $details)->call('submit')->assertHasNoErrors();
        $record = ServiceRequest::query()->sole();
        $this->assertSame('', $record->payload['middle_name']);
        $this->assertSame('29-02-2000', $record->payload['dob']);
        $this->assertSame(0, $record->quoted_price);
    }

    public function test_required_fields_exclude_the_optional_middle_name(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(DemographicsSearch::class)->call('submit')
            ->assertHasErrors(['details.first_name', 'details.last_name', 'details.dob', 'details.gender'])
            ->assertHasNoErrors('details.middle_name');
        $this->assertDatabaseCount('service_requests', 0);
    }

    #[TestWith(['first_name', ''])]
    #[TestWith(['last_name', ''])]
    #[TestWith(['first_name', ['invalid']])]
    #[TestWith(['dob', '1990-12-31'])]
    #[TestWith(['dob', '12-31-1990'])]
    #[TestWith(['dob', '31-02-1990'])]
    #[TestWith(['dob', '29-02-1991'])]
    #[TestWith(['dob', '1-1-1990'])]
    #[TestWith(['dob', '31/12/1990'])]
    #[TestWith(['dob', '01-01-2999'])]
    #[TestWith(['gender', 'Unknown'])]
    #[TestWith(['gender', 'male'])]
    public function test_invalid_values_never_save_a_request_or_charge_wallet(string $field, mixed $value): void
    {
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $this->actingAs($user);
        Livewire::test(DemographicsSearch::class)->set('details', [...$this->details(), $field => $value])
            ->call('submit')->assertHasErrors('details.'.$field);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(50000, $user->fresh()->wallet_balance);
    }

    public function test_direct_submission_also_enforces_the_requested_date_format(): void
    {
        $user = User::factory()->create();
        try {
            app(SubmitServiceRequest::class)->create($user, 'demographics-search', 'search', [...$this->details(), 'dob' => '1990-12-31'], (string) Str::uuid());
            $this->fail('The request service must reject dates outside DD-MM-YYYY.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('input.dob', $exception->errors());
        }
        $this->assertDatabaseCount('service_requests', 0);
    }

    #[TestWith([null])]
    #[TestWith([60000])]
    public function test_unset_or_unaffordable_prices_prevent_submissions(?int $price): void
    {
        if ($price !== null) {
            ServicePrice::factory()->create(['service' => 'demographics-search', 'method' => 'search', 'amount' => $price]);
        }
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $this->actingAs($user);
        Livewire::test(DemographicsSearch::class)->set('details', $this->details())->call('submit')->assertHasErrors('serviceAvailability');
        $this->assertSame(50000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_pricing_and_unavailable_type_allow_history_but_block_new_requests(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Prices::class)->set('prices.demographics-search.search', '125')
            ->set('unavailableTypes.demographics-search.search', true)->call('save')->assertHasNoErrors();
        $record = ServiceRequest::factory()->create(['service' => 'demographics-search', 'method' => 'search', 'payload' => $this->details()]);
        $this->actingAs($record->user);
        $this->get(route('dashboard'))->assertOk()->assertSee('href="'.route('demographics.search').'"', false);
        Livewire::test(DemographicsSearch::class)->assertSee($record->reference)->assertViewHas('canSubmit', false)
            ->call('submit')->assertHasErrors('serviceAvailability');
        $this->assertSame(12500, ServicePrice::amountFor('demographics-search', 'search'));
        $this->assertDatabaseCount('wallet_transactions', 0);
        SiteSetting::create(['key' => ServiceAvailability::settingKey('demographics-search'), 'value' => '1']);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('DEMOGRAPHICS SEARCH');
        $this->get(route('demographics.search'))->assertRedirect(route('dashboard'));
    }

    public function test_user_history_is_private(): void
    {
        $record = ServiceRequest::factory()->create(['service' => 'demographics-search', 'method' => 'search', 'payload' => $this->details()]);
        $this->actingAs(User::factory()->create());
        Livewire::test(DemographicsSearch::class)->assertDontSee($record->reference)->assertDontSee('16-05-1990');
    }

    public function test_logged_out_and_inactive_users_cannot_submit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Livewire::test(DemographicsSearch::class);
        Auth::logout();
        $form->call('submit')->assertForbidden();
        $this->actingAs($user);
        $form = Livewire::test(DemographicsSearch::class);
        $user->forceFill(['is_active' => false])->save();
        $form->call('submit')->assertForbidden();
        $this->assertDatabaseCount('service_requests', 0);
    }

    private function details(): array
    {
        return ['first_name' => 'Taylor', 'last_name' => 'Example', 'middle_name' => '', 'dob' => '16-05-1990', 'gender' => 'Male'];
    }
}
