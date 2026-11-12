<?php

namespace Tests\Feature;

use App\Livewire\Admin\Prices;
use App\Models\BvnVerificationRecord;
use App\Models\NinVerificationRecord;
use App\Models\ServicePrice;
use App\Models\SlipPurchase;
use App\Models\User;
use App\Models\VerificationRecord;
use App\NinSlipRenderer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class SlipPricingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_can_save_all_six_slip_prices_independently(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'nin', 'amount' => 15000]);

        Livewire::test(Prices::class)->assertSee('NIN verification slips')->assertSee('BVN verification slips')
            ->assertSet('slipPrices.nin-slips.regular', '0.00')
            ->set('slipPrices.nin-slips.regular', '15.50')
            ->set('slipPrices.nin-slips.premium', '75')
            ->set('slipPrices.nin-slips.standard', '50')
            ->set('slipPrices.nin-slips.digital', '0')
            ->set('slipPrices.bvn-slips.slip', '35')
            ->set('slipPrices.bvn-slips.plastic', '65')->call('save')->assertHasNoErrors();

        foreach ([
            ['nin-slips', 'regular', 1550], ['nin-slips', 'premium', 7500], ['nin-slips', 'standard', 5000],
            ['nin-slips', 'digital', 0], ['bvn-slips', 'slip', 3500], ['bvn-slips', 'plastic', 6500],
        ] as [$service, $slip, $minor]) {
            $this->assertSame($minor, ServicePrice::amountFor($service, $slip));
        }
        $this->assertSame(15000, ServicePrice::amountFor('nin-v1', 'nin'));
        Livewire::test(Prices::class)->assertSet('slipPrices.nin-slips.regular', '15.50')
            ->assertSet('slipPrices.bvn-slips.plastic', '65.00');
        $this->assertDatabaseHas('admin_audits', ['event' => 'prices.updated']);
    }

    #[TestWith(['-1'])]
    #[TestWith(['1.234'])]
    #[TestWith(['1e3'])]
    public function test_invalid_slip_prices_do_not_change_saved_pricing(string $amount): void
    {
        ServicePrice::factory()->create(['service' => 'nin-slips', 'method' => 'premium', 'amount' => 7500]);
        $this->actingAs(User::factory()->administrator()->create());

        Livewire::test(Prices::class)->set('slipPrices.nin-slips.premium', $amount)->call('save')
            ->assertHasErrors(['slipPrices.nin-slips.premium']);

        $this->assertSame(7500, ServicePrice::amountFor('nin-slips', 'premium'));
        $this->assertDatabaseCount('admin_audits', 0);
    }

    public function test_blank_prices_disable_downloads_while_zero_prices_remain_free(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Prices::class)->set('slipPrices.nin-slips.regular', '')->set('slipPrices.nin-slips.premium', '0')
            ->call('save')->assertHasNoErrors();
        $record = NinVerificationRecord::factory()->create();
        $this->actingAs($record->user);

        $this->get(route('nin.v1.result', ['verification' => $record->reference]))->assertOk()->assertSee('Unavailable')->assertSee('Free');
        $this->get(route('nin.v1.slip', ['verification' => $record->reference, 'slip' => 'regular']))->assertForbidden();
        $this->get(route('nin.v1.slip', ['verification' => $record->reference, 'slip' => 'premium']))->assertOk();

        $this->assertDatabaseCount('slip_purchases', 0);
    }

    public function test_price_viewers_cannot_save_slip_prices(): void
    {
        $this->actingAs(User::factory()->staff(['prices.view'])->create());

        Livewire::test(Prices::class)->set('slipPrices.bvn-slips.plastic', '100')->call('save')->assertForbidden();

        $this->assertDatabaseCount('service_prices', 0);
    }

    #[TestWith(['nin'])]
    #[TestWith(['bvn'])]
    public function test_paid_slips_charge_once_and_allow_free_repeat_downloads(string $kind): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        $record = $this->record($kind, $user);
        $slip = $kind === 'nin' ? 'premium' : 'plastic';
        $route = $kind === 'nin' ? 'nin.v1.slip' : 'bvn.slip';
        $resultRoute = $kind === 'nin' ? 'nin.v1.result' : 'bvn.result';
        $pricing = ServicePrice::factory()->create(['service' => $kind.'-slips', 'method' => $slip, 'amount' => 7500]);
        $url = route($route, ['verification' => $record->reference, 'slip' => $slip]);
        $this->actingAs($user);
        $this->get(route($resultRoute, ['verification' => $record->reference]))->assertOk()->assertSee('₦75.00')->assertSee('Buy &amp; download', false);
        $this->get($url)->assertForbidden();
        $this->assertSame(100000, $user->fresh()->wallet_balance);

        $this->post($url, ['expected_price' => '7500'])->assertOk();

        $this->assertSame(92500, $user->fresh()->wallet_balance);
        $purchase = SlipPurchase::query()->sole();
        $this->assertSame(7500, $purchase->price);
        $this->assertDatabaseHas('wallet_transactions', ['id' => $purchase->wallet_transaction_id, 'type' => 'service_debit', 'amount' => '75.00']);
        $this->post($url, ['expected_price' => '7500'])->assertOk();
        $this->get($url)->assertOk();
        $this->assertDatabaseCount('slip_purchases', 1);
        $this->assertSame(92500, $user->fresh()->wallet_balance);

        $pricing->update(['amount' => null]);
        $this->get($url)->assertOk();
        $this->get(route($resultRoute, ['verification' => $record->reference]))->assertOk()->assertSee('Purchased');
        $this->assertSame(92500, $user->fresh()->wallet_balance);
    }

    #[TestWith(['nin', 'insufficient-balance'])]
    #[TestWith(['bvn', 'insufficient-balance'])]
    #[TestWith(['nin', 'price-changed'])]
    #[TestWith(['bvn', 'price-changed'])]
    #[TestWith(['nin', 'unavailable'])]
    #[TestWith(['bvn', 'unavailable'])]
    public function test_failed_purchases_do_not_debit_wallets(string $kind, string $scenario): void
    {
        $user = User::factory()->create(['wallet_balance' => $scenario === 'insufficient-balance' ? 100 : 100000]);
        $record = $this->record($kind, $user);
        $slip = $kind === 'nin' ? 'premium' : 'plastic';
        $route = $kind === 'nin' ? 'nin.v1.slip' : 'bvn.slip';
        $resultRoute = $kind === 'nin' ? 'nin.v1.result' : 'bvn.result';
        ServicePrice::factory()->create(['service' => $kind.'-slips', 'method' => $slip, 'amount' => $scenario === 'unavailable' ? null : 7500]);
        $this->actingAs($user);
        $resultUrl = route($resultRoute, ['verification' => $record->reference]);

        $this->from($resultUrl)->post(route($route, ['verification' => $record->reference, 'slip' => $slip]), [
            'expected_price' => $scenario === 'price-changed' ? '0' : '7500',
        ])->assertRedirect($resultUrl)->assertSessionHasErrors('slipDownload');

        $this->assertSame($scenario === 'insufficient-balance' ? 100 : 100000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('slip_purchases', 0);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    #[TestWith(['nin'])]
    #[TestWith(['bvn'])]
    public function test_slip_purchases_require_authentication_and_record_ownership(string $kind): void
    {
        $owner = User::factory()->create(['wallet_balance' => 100000]);
        $record = $this->record($kind, $owner);
        $slip = $kind === 'nin' ? 'premium' : 'plastic';
        $route = $kind === 'nin' ? 'nin.v1.slip' : 'bvn.slip';
        ServicePrice::factory()->create(['service' => $kind.'-slips', 'method' => $slip, 'amount' => 7500]);
        $url = route($route, ['verification' => $record->reference, 'slip' => $slip]);

        $this->post($url, ['expected_price' => '7500'])->assertRedirect(route('home'));
        $this->actingAs(User::factory()->create(['wallet_balance' => 100000]))->post($url, ['expected_price' => '7500'])->assertNotFound();
        $this->actingAs($owner);
        $record->update(['status' => 'failed']);
        $this->post($url, ['expected_price' => '7500'])->assertNotFound();

        $this->assertSame(100000, $owner->fresh()->wallet_balance);
        $this->assertDatabaseCount('slip_purchases', 0);
    }

    public function test_failed_slip_rendering_does_not_charge_the_wallet(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        $record = NinVerificationRecord::factory()->for($user)->create();
        ServicePrice::factory()->create(['service' => 'nin-slips', 'method' => 'premium', 'amount' => 7500]);
        $this->mock(NinSlipRenderer::class)->shouldReceive('render')->once()->andThrow(new RuntimeException('Rendering failed'));
        $this->actingAs($user);

        $this->post(route('nin.v1.slip', ['verification' => $record->reference, 'slip' => 'premium']), ['expected_price' => '7500'])->assertServerError();

        $this->assertSame(100000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('slip_purchases', 0);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    private function record(string $kind, User $user): VerificationRecord
    {
        return $kind === 'nin' ? NinVerificationRecord::factory()->for($user)->create() : BvnVerificationRecord::factory()->for($user)->create();
    }
}
