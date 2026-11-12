<?php

namespace Tests\Feature;

use App\Livewire\Admin\Prices;
use App\Livewire\BvnModification;
use App\Livewire\BvnRetrieval;
use App\Livewire\BvnVerification;
use App\Livewire\Dashboard;
use App\Livewire\Delinking;
use App\Livewire\InstantIpe;
use App\Livewire\IpeRequest;
use App\Livewire\NinModification;
use App\Livewire\NinValidation;
use App\Livewire\NinVerification;
use App\Livewire\Personalization;
use App\Models\NinVerificationRecord;
use App\Models\PersonalizationRequest;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\Models\SiteSetting;
use App\Models\User;
use App\ServiceAvailability;
use App\ServiceCatalog;
use App\SlipDownloadService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceTypeAvailabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_every_priced_type_and_slip_has_an_independent_persisted_unavailable_switch(): void
    {
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'nin', 'amount' => 12345]);
        $this->actingAs(User::factory()->administrator()->create());
        $form = Livewire::test(Prices::class);
        $flags = [];
        foreach (ServiceCatalog::all() as $service => $definition) {
            foreach ($definition['methods'] as $method => $label) {
                $flags[$service][$method] = true;
                $form->assertSee('wire:model="unavailableTypes.'.$service.'.'.$method.'"', false);
            }
        }
        foreach (ServiceCatalog::slips() as $service => $definition) {
            foreach ($definition['slips'] as $method => $details) {
                $flags[$service][$method] = true;
                $form->assertSee('wire:model="unavailableTypes.'.$service.'.'.$method.'"', false);
            }
        }
        $form->set('unavailableTypes', $flags)->call('save')->assertHasNoErrors();
        $reloaded = Livewire::test(Prices::class);
        foreach ($flags as $service => $methods) {
            foreach ($methods as $method => $flag) {
                $reloaded->assertSet('unavailableTypes.'.$service.'.'.$method, true);
                $this->assertDatabaseHas('site_settings', ['key' => ServiceAvailability::unavailableSettingKey($service, $method), 'value' => '1']);
            }
        }
        $reloaded->set('unavailableTypes.nin-v1.phone', false)->call('save')->assertHasNoErrors();
        $this->assertTrue(ServiceAvailability::canSubmit('nin-v1', 'phone'));
        $this->assertFalse(ServiceAvailability::canSubmit('nin-v1', 'nin'));
        $this->assertSame(12345, ServicePrice::amountFor('nin-v1', 'nin'));
        $this->assertDatabaseHas('admin_audits', ['event' => 'services.availability_updated']);
    }

    public function test_invalid_or_unauthorized_type_changes_do_not_save(): void
    {
        $this->actingAs(User::factory()->staff(['prices.view'])->create());
        Livewire::test(Prices::class)->set('unavailableTypes.nin-v1.phone', true)->call('save')->assertForbidden();
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Prices::class)->set('unavailableTypes.nin-v1.phone', 'invalid')->call('save')->assertHasErrors('unavailableTypes.nin-v1.phone');
        Livewire::test(Prices::class)->set('unavailableTypes.nin-v1.unknown', true)->call('save')->assertHasErrors('unavailableTypes.nin-v1');
        $this->assertDatabaseCount('site_settings', 0);
    }

    public function test_parent_unavailable_service_remains_clickable_and_history_is_visible(): void
    {
        $user = User::factory()->create();
        $record = ServiceRequest::factory()->create(['user_id' => $user->id, 'service' => 'delinking', 'method' => 'nin', 'payload' => ['nin' => '01234567890'], 'reply' => 'Existing request reply']);
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('delinking'), 'value' => '1']);
        $this->actingAs($user);
        $page = $this->get(route('dashboard'))->assertOk()->assertSee('href="'.route('delinking').'"', false)->assertSee('Temporarily unavailable');
        Livewire::test(Dashboard::class)->call('selectService', 'delinking')->assertRedirect(route('delinking'));
        $this->get(route('delinking'))->assertOk()->assertSee($record->reference)->assertSee('Existing request reply')
            ->assertSee('type="submit" disabled', false)->assertDontSee('fieldset disabled', false);
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('nin-modification'), 'value' => '1']);
        $this->get(route('nin.modification'))->assertOk()
            ->assertSee('href="'.route('nin.modification.form', ['stage' => 'first']).'"', false)
            ->assertSee('href="'.route('nin.modification.form', ['stage' => 'second']).'"', false)
            ->assertDontSee('inert aria-disabled', false);
    }

    #[DataProvider('simpleTypes')]
    public function test_unavailable_types_block_only_the_selected_submission(string $service, string $method, string $component, array $parameters, array $properties, string $action): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        $this->actingAs($user);
        $form = Livewire::test($component, $parameters);
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey($service, $method), 'value' => '1']);
        Http::preventStrayRequests();
        foreach ($properties as $key => $value) {
            $form->set($key, $value);
        }
        $form->call('$refresh')->assertViewHas('canSubmit', false)->call($action)->assertHasErrors('serviceAvailability');
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        Http::assertNothingSent();
    }

    public static function simpleTypes(): array
    {
        return [
            'nin v1' => ['nin-v1', 'nin', NinVerification::class, ['version' => 'v1'], ['method' => 'nin'], 'verify'],
            'nin v2 phone' => ['nin-v2', 'phone', NinVerification::class, ['version' => 'v2'], ['method' => 'phone'], 'verify'],
            'nin v3 demographics' => ['nin-v3', 'demographics', NinVerification::class, ['version' => 'v3'], ['method' => 'demographics'], 'verify'],
            'bvn verification' => ['bvn-verification', 'bvn', BvnVerification::class, [], [], 'verify'],
            'bvn phone retrieval' => ['bvn-retrieval', 'phone', BvnRetrieval::class, [], ['method' => 'phone'], 'submit'],
            'bvn crm retrieval' => ['bvn-retrieval', 'crm', BvnRetrieval::class, [], ['method' => 'crm'], 'submit'],
            'ipe clearing' => ['ipe-clearing', 'tracking', IpeRequest::class, ['service' => 'ipe-clearing'], [], 'submit'],
            'mod ipe' => ['mod-ipe', 'tracking', IpeRequest::class, ['service' => 'mod-ipe'], [], 'submit'],
            'instant single' => ['instant-ipe', 'single', InstantIpe::class, [], ['mode' => 'single'], 'submit'],
            'instant bulk' => ['instant-ipe', 'bulk', InstantIpe::class, [], ['mode' => 'bulk'], 'submit'],
            'validation' => ['validations', 'nin', NinValidation::class, [], [], 'submit'],
            'personalization' => ['personalization', 'tracking', Personalization::class, [], ['trackingId' => 'S7Y0OG319000HEM'], 'submit'],
        ];
    }

    public function test_another_nin_method_can_still_be_submitted(): void
    {
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('nin-v1', 'nin'), 'value' => '1']);
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'phone', 'amount' => 1000]);
        config(['services.verify.url' => 'https://verify.example.test', 'services.verify.token' => 'token']);
        Http::preventStrayRequests();
        Http::fake(['https://verify.example.test/api/v1/verify' => Http::response(['status' => true, 'message' => ['idNumber' => '12345678901', 'firstName' => 'Taylor', 'lastName' => 'Example']])]);
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $this->actingAs($user);
        Livewire::test(NinVerification::class)->assertViewHas('canSubmit', false)->set('method', 'phone')->assertViewHas('canSubmit', true)
            ->set('phone', '08165072514')->set('consent', true)->call('verify')->assertHasNoErrors()->assertRedirect();
        $this->assertSame(49000, $user->fresh()->wallet_balance);
        Http::assertSentCount(1);
    }

    public function test_combined_manual_types_cannot_bypass_an_unavailable_type(): void
    {
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $this->actingAs($user);
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('bvn-modification', 'agency_phone'), 'value' => '1']);
        ServicePrice::factory()->create(['service' => 'bvn-modification', 'method' => 'agency_phone', 'amount' => 1000]);
        ServicePrice::factory()->create(['service' => 'bvn-modification', 'method' => 'agency_dob', 'amount' => 2000]);
        Livewire::test(BvnModification::class)->set('kind', 'agency')->set('types', ['dob', 'phone'])
            ->assertViewHas('canSubmit', false)->set('bvn', '22484232222')->set('nin', '12345678901')
            ->set('dob', '1990-01-01')->set('phone', '08165072514')->call('submit')->assertHasErrors('serviceAvailability')
            ->set('types', ['dob'])->assertViewHas('canSubmit', true)->call('submit')->assertHasNoErrors();
        $this->assertSame(48000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_nin_first_and_second_type_availability_are_independent(): void
    {
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('nin-modification', 'first_gender'), 'value' => '1']);
        $this->actingAs(User::factory()->create());
        Livewire::test(NinModification::class, ['stage' => 'first'])->set('types', ['gender'])
            ->set('nin', '12345678901')->set('details.gender', 'female')->assertViewHas('canSubmit', false)->call('submit')->assertHasErrors('serviceAvailability');
        Livewire::test(NinModification::class, ['stage' => 'second'])->set('types', ['gender'])->assertViewHas('canSubmit', true);
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_self_service_combined_types_honor_individual_flags(): void
    {
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('delinking', 'email_retrieve'), 'value' => '1']);
        $this->actingAs(User::factory()->create());
        Livewire::test(Delinking::class)->assertViewHas('canSubmit', true)->set('types', ['delinking', 'email_retrieve'])
            ->set('nin', '12345678901')->assertViewHas('canSubmit', false)->call('submit')->assertHasErrors('serviceAvailability');
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_personalization_status_check_still_calls_provider_when_new_requests_are_unavailable(): void
    {
        config(['services.tracking_id.tracking_url' => 'https://tracking.example.test', 'services.tracking_id.tracking_token' => 'token']);
        $record = PersonalizationRequest::factory()->create(['tracking_id' => 'S7Y0OG319000HEM', 'provider_id' => '12345', 'status' => 'in_progress']);
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('personalization'), 'value' => '1']);
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('personalization', 'tracking'), 'value' => '1']);
        Http::preventStrayRequests();
        Http::fake(['https://tracking.example.test/api/personalization/check' => Http::response(['success' => true, 'id' => 12345, 'tracking_id' => 'S7Y0OG319000HEM', 'status' => 'In-Progress'])]);
        $this->actingAs($record->user);
        Livewire::test(Personalization::class)->assertSee($record->reference)->assertViewHas('canSubmit', false)
            ->call('checkResult', $record->reference)->assertHasNoErrors()->assertSet('notice', 'In-Progress');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_unavailable_slips_block_new_purchases_but_allow_existing_purchased_downloads(): void
    {
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $record = NinVerificationRecord::factory()->create(['user_id' => $user->id]);
        ServicePrice::factory()->create(['service' => 'nin-slips', 'method' => 'premium', 'amount' => 1000]);
        $downloads = app(SlipDownloadService::class);
        $downloads->purchase($user, $record, 'premium', 1000);
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('nin-slips', 'premium'), 'value' => '1']);
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('nin-slips', 'regular'), 'value' => '1']);
        $this->assertTrue($downloads->canDownload($record, 'premium'));
        $this->assertFalse($downloads->canDownload($record, 'regular'));
        $this->assertTrue($downloads->canDownload($record, 'standard'));
        $this->actingAs($user);
        $this->post(route('nin.v1.slip.purchase', ['verification' => $record->reference, 'slip' => 'regular']), ['expected_price' => 0])
            ->assertSessionHasErrors('slipDownload');
        $this->assertSame(49000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('slip_purchases', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
    }
}
