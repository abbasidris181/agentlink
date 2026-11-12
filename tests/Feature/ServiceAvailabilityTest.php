<?php

namespace Tests\Feature;

use App\Livewire\Admin\Prices;
use App\Livewire\BvnRetrieval;
use App\Livewire\BvnVerification;
use App\Livewire\Dashboard;
use App\Livewire\Delinking;
use App\Livewire\InstantIpe;
use App\Livewire\IpeRequest;
use App\Livewire\NinValidation;
use App\Livewire\NinVerification;
use App\Models\AdminAudit;
use App\Models\ServicePrice;
use App\Models\SiteSetting;
use App\Models\User;
use App\ServiceAvailability;
use App\ServiceCatalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServiceAvailabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function disable(string $service): void
    {
        SiteSetting::updateOrCreate(['key' => ServiceAvailability::settingKey($service)], ['value' => '1']);
    }

    public function test_all_services_start_enabled_without_changing_unset_prices(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $component = Livewire::test(Prices::class);
        foreach (array_keys(ServiceCatalog::all()) as $service) {
            $component->assertSet('enabledServices.'.$service, true);
            $this->assertTrue(ServiceAvailability::isEnabled($service));
        }
        $this->assertDatabaseCount('service_prices', 0);
        $this->assertDatabaseCount('site_settings', 0);
    }

    public function test_admin_can_disable_a_service_without_losing_its_prices_or_disabling_other_versions(): void
    {
        $admin = User::factory()->administrator()->create();
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'phone', 'amount' => 12345]);
        $this->actingAs($admin);
        Livewire::test(Prices::class)->set('enabledServices.nin-v1', false)->call('save')->assertHasNoErrors();
        $this->assertFalse(ServiceAvailability::isEnabled('nin-v1'));
        $this->assertTrue(ServiceAvailability::isEnabled('nin-v2'));
        $this->assertTrue(ServiceAvailability::isEnabled('nin-v3'));
        $this->assertSame(12345, ServicePrice::amountFor('nin-v1', 'phone'));
        $this->assertDatabaseHas('admin_audits', ['event' => 'services.availability_updated', 'actor_id' => $admin->id]);
        Livewire::test(Prices::class)->assertSet('enabledServices.nin-v1', false)->set('enabledServices.nin-v1', true)->call('save')->assertHasNoErrors();
        $this->assertTrue(ServiceAvailability::isEnabled('nin-v1'));
        $this->assertSame(12345, ServicePrice::amountFor('nin-v1', 'phone'));
    }

    public function test_view_only_staff_cannot_change_availability_but_price_editors_can(): void
    {
        $this->actingAs(User::factory()->staff(['prices.view'])->create());
        Livewire::test(Prices::class)->set('enabledServices.instant-ipe', false)->call('save')->assertForbidden();
        $this->assertTrue(ServiceAvailability::isEnabled('instant-ipe'));
        $this->actingAs(User::factory()->staff(['prices.edit'])->create());
        Livewire::test(Prices::class)->set('enabledServices.instant-ipe', false)->call('save')->assertHasNoErrors();
        $this->assertFalse(ServiceAvailability::isEnabled('instant-ipe'));
    }

    public function test_invalid_prices_and_unknown_service_keys_do_not_save_availability(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Prices::class)->set('enabledServices.nin-v1', false)->set('prices.nin-v1.nin', '-1')->call('save')->assertHasErrors('prices.nin-v1.nin');
        $this->assertTrue(ServiceAvailability::isEnabled('nin-v1'));
        Livewire::test(Prices::class)->set('enabledServices.unknown', false)->call('save')->assertHasErrors('enabledServices');
        Livewire::test(Prices::class)->set('enabledServices.nin-v1', 'invalid')->call('save')->assertHasErrors('enabledServices.nin-v1');
        $this->assertDatabaseCount('site_settings', 0);
        $this->assertDatabaseCount('admin_audits', 0);
    }

    public function test_dashboard_hides_disabled_services_and_removes_navigation_links(): void
    {
        $this->disable('bvn-retrieval');
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk()->assertDontSee('BVN RETRIEVAL')
            ->assertDontSee('href="'.route('bvn.retrieval').'"', false)->assertSee('href="'.route('nin.v1').'"', false);
        Livewire::test(Dashboard::class)->call('selectService', 'bvn retrival')->assertHasErrors('serviceAvailability');
        SiteSetting::query()->where('key', ServiceAvailability::settingKey('bvn-retrieval'))->update(['value' => '0']);
        $this->get(route('dashboard'))->assertOk()->assertSee('href="'.route('bvn.retrieval').'"', false);
    }

    #[DataProvider('serviceForms')]
    public function test_disabled_services_block_direct_links_mounts_and_already_open_form_submissions(string $service, string $component, array $parameters, string $action, string $route): void
    {
        $this->actingAs(User::factory()->create());
        $form = Livewire::test($component, $parameters);
        $this->disable($service);
        $this->get(route($route))->assertRedirect(route('dashboard'))->assertSessionHas('status', 'This service is temporarily unavailable. Please try again later.');
        $this->getJson(route($route))->assertStatus(503)->assertJsonPath('message', 'This service is temporarily unavailable. Please try again later.');
        Livewire::test($component, $parameters)->assertStatus(503);
        $form->call($action)->assertHasErrors('serviceAvailability')->assertSet('showAvailability', false)->assertSee('This service is temporarily unavailable.');
        $this->assertDatabaseCount('wallet_transactions', 0);
        SiteSetting::query()->where('key', ServiceAvailability::settingKey($service))->update(['value' => '0']);
        $this->get(route($route))->assertOk();
    }

    #[DataProvider('serviceForms')]
    public function test_unavailable_services_block_new_submissions_without_disabling_history(string $service, string $component, array $parameters, string $action, string $route): void
    {
        $this->actingAs(User::factory()->create());
        $form = Livewire::test($component, $parameters);
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey($service), 'value' => '1']);
        $this->get(route($route))->assertOk()->assertSee('Temporarily unavailable. Please try again later.')
            ->assertSee('type="submit" disabled', false)->assertDontSee('fieldset disabled', false);
        $form->call($action)->assertHasErrors('serviceAvailability');
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    /** @return array<string, array{string, class-string, array<string, string>, string, string}> */
    public static function serviceForms(): array
    {
        return [
            'nin v1' => ['nin-v1', NinVerification::class, ['version' => 'v1'], 'verify', 'nin.v1'],
            'nin v2' => ['nin-v2', NinVerification::class, ['version' => 'v2'], 'verify', 'nin.v2'],
            'nin v3' => ['nin-v3', NinVerification::class, ['version' => 'v3'], 'verify', 'nin.v3'],
            'bvn verification' => ['bvn-verification', BvnVerification::class, [], 'verify', 'bvn.verify'],
            'bvn retrieval' => ['bvn-retrieval', BvnRetrieval::class, [], 'submit', 'bvn.retrieval'],
            'ipe clearing' => ['ipe-clearing', IpeRequest::class, ['service' => 'ipe-clearing'], 'submit', 'ipe.clearing'],
            'mod ipe' => ['mod-ipe', IpeRequest::class, ['service' => 'mod-ipe'], 'submit', 'ipe.mod'],
            'instant ipe' => ['instant-ipe', InstantIpe::class, [], 'submit', 'ipe.instant'],
            'validations' => ['validations', NinValidation::class, [], 'submit', 'validations'],
            'delinking' => ['delinking', Delinking::class, [], 'submit', 'delinking'],
        ];
    }

    public function test_availability_and_price_changes_roll_back_when_auditing_fails(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $component = Livewire::test(Prices::class)->set('enabledServices.nin-v1', false)->set('prices.nin-v1.nin', '10');
        AdminAudit::creating(function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            $component->call('save');
            $this->fail('Availability should not change without its audit.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('Audit unavailable', $exception->getMessage());
        } finally {
            AdminAudit::flushEventListeners();
        }
        $this->assertTrue(ServiceAvailability::isEnabled('nin-v1'));
        $this->assertDatabaseCount('service_prices', 0);
    }
}
