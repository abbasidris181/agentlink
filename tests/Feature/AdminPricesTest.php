<?php

namespace Tests\Feature;

use App\Livewire\Admin\Prices;
use App\Livewire\NinVerification;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminPricesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_every_nin_version_and_method_has_an_independent_price(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $component = Livewire::test(Prices::class);
        $expected = [];
        $amount = 100;

        foreach (['nin-v1', 'nin-v2', 'nin-v3'] as $service) {
            foreach (['nin', 'phone', 'demographics'] as $method) {
                $amount += 7;
                $component->set('prices.'.$service.'.'.$method, (string) $amount);
                $expected[] = [$service, $method, (string) $amount];
            }
        }

        $component->set('prices.bvn-retrieval.phone', '450.25')->set('prices.bvn-retrieval.crm', '625.50')
            ->set('prices.instant-ipe.single', '80.00')->set('prices.instant-ipe.bulk', '65.75')
            ->call('save')->assertHasNoErrors()->assertSee('Service prices saved.');

        foreach ($expected as [$service, $method, $decimal]) {
            $this->assertDatabaseHas('service_prices', ['service' => $service, 'method' => $method, 'amount' => $decimal]);
        }

        $this->assertSame(45025, ServicePrice::amountFor('bvn-retrieval', 'phone'));
        $this->assertSame(62550, ServicePrice::amountFor('bvn-retrieval', 'crm'));
        $this->assertSame(6575, ServicePrice::amountFor('instant-ipe', 'bulk'));
        $this->assertSame(8000, ServicePrice::amountFor('instant-ipe', 'single'));
        $this->assertDatabaseHas('admin_audits', ['event' => 'prices.updated']);
    }

    public function test_charge_error_toggles_are_independent_and_saved_for_every_nin_method(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $component = Livewire::test(Prices::class)->assertSee('Charge error');
        foreach (['nin-v1', 'nin-v2', 'nin-v3'] as $service) {
            foreach (['nin', 'phone', 'demographics'] as $method) {
                $component->assertSet('chargeErrors.'.$service.'.'.$method, false)
                    ->set('chargeErrors.'.$service.'.'.$method, true);
            }
        }
        $component->call('save')->assertHasNoErrors();
        $component->set('chargeErrors.nin-v1.nin', false)->call('save')->assertHasNoErrors();

        $reloaded = Livewire::test(Prices::class);
        foreach (['nin-v1', 'nin-v2', 'nin-v3'] as $service) {
            foreach (['nin', 'phone', 'demographics'] as $method) {
                $enabled = ! ($service === 'nin-v1' && $method === 'nin');
                $reloaded->assertSet('chargeErrors.'.$service.'.'.$method, $enabled);
                $this->assertDatabaseHas('service_prices', ['service' => $service, 'method' => $method, 'charge_errors' => $enabled]);
            }
        }
        $this->assertDatabaseHas('admin_audits', ['event' => 'prices.updated']);
        $this->assertDatabaseHas('service_prices', ['service' => 'bvn-verification', 'charge_errors' => false]);
    }

    public function test_invalid_charge_error_values_do_not_change_prices_or_create_audits(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        ServicePrice::factory()->create(['charge_errors' => false]);

        Livewire::test(Prices::class)->set('chargeErrors.nin-v1.nin', 'invalid')->call('save')
            ->assertHasErrors(['chargeErrors.nin-v1.nin']);

        $this->assertDatabaseHas('service_prices', ['service' => 'nin-v1', 'method' => 'nin', 'charge_errors' => false]);
        $this->assertDatabaseCount('admin_audits', 0);
    }

    public function test_price_viewers_cannot_change_error_charging(): void
    {
        $this->actingAs(User::factory()->staff(['prices.view'])->create());

        Livewire::test(Prices::class)->set('chargeErrors.nin-v1.nin', true)->call('save')->assertForbidden();

        $this->assertDatabaseCount('service_prices', 0);
        $this->assertDatabaseCount('admin_audits', 0);
    }

    #[TestWith(['-1'])]
    #[TestWith(['1.234'])]
    #[TestWith(['1e3'])]
    #[TestWith(['1000000000'])]
    public function test_invalid_prices_do_not_change_saved_prices(string $amount): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        ServicePrice::factory()->create(['amount' => 15000]);

        Livewire::test(Prices::class)->set('prices.nin-v1.nin', $amount)->call('save')
            ->assertHasErrors(['prices.nin-v1.nin']);

        $this->assertSame(15000, ServicePrice::amountFor('nin-v1', 'nin'));
        $this->assertDatabaseCount('admin_audits', 0);
    }

    public function test_blank_and_zero_prices_remain_distinct_and_unknown_keys_are_not_saved(): void
    {
        $this->actingAs(User::factory()->administrator()->create());

        Livewire::test(Prices::class)->set('prices.nin-v1.nin', '0')->set('prices.nin-v2.nin', '')
            ->set('prices.unknown.secret', '99')->call('save')->assertHasNoErrors();

        $this->assertSame(0, ServicePrice::amountFor('nin-v1', 'nin'));
        $this->assertNull(ServicePrice::amountFor('nin-v2', 'nin'));
        $this->assertDatabaseMissing('service_prices', ['service' => 'unknown']);
    }

    public function test_customer_forms_display_the_price_for_the_selected_version_and_method(): void
    {
        ServicePrice::factory()->create(['service' => 'nin-v2', 'method' => 'phone', 'amount' => 45025]);
        ServicePrice::factory()->create(['service' => 'nin-v2', 'method' => 'nin', 'amount' => 12500]);
        $this->actingAs(User::factory()->create());

        Livewire::test(NinVerification::class, ['version' => 'v2'])->assertSee('125.00')
            ->set('method', 'phone')->assertSee('450.25')->assertDontSee('125.00');
    }
}
