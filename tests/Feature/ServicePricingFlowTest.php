<?php

namespace Tests\Feature;

use App\Livewire\Admin\Prices;
use App\Livewire\BvnModification;
use App\Livewire\NinModification;
use App\Livewire\NinVerification;
use App\Livewire\Personalization;
use App\Models\PersonalizationRequest;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\ServiceAvailability;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ServicePricingFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_saves_each_new_type_price_and_independent_availability_flags(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Prices::class)
            ->set('prices.nin-modification.first_name', '100.50')
            ->set('prices.nin-modification.second_name', '200')
            ->set('prices.nin-modification.first_dob_above_15', '750')
            ->set('prices.bvn-modification.agency_name', '300')
            ->set('prices.bvn-modification.access_name', '400')
            ->set('prices.bvn-modification.access_dob', '500')
            ->set('prices.delinking.email_retrieve', '50')
            ->set('prices.personalization.tracking', '60')
            ->set('unavailableServices.nin-modification', true)
            ->set('enabledServices.bvn-modification', false)
            ->call('save')->assertHasNoErrors();
        $this->assertSame(10050, ServicePrice::amountFor('nin-modification', 'first_name'));
        $this->assertSame(20000, ServicePrice::amountFor('nin-modification', 'second_name'));
        $this->assertSame(75000, ServicePrice::amountFor('nin-modification', 'first_dob_above_15'));
        $this->assertSame(30000, ServicePrice::amountFor('bvn-modification', 'agency_name'));
        $this->assertSame(40000, ServicePrice::amountFor('bvn-modification', 'access_name'));
        $this->assertSame(50000, ServicePrice::amountFor('bvn-modification', 'access_dob'));
        $this->assertSame(5000, ServicePrice::amountFor('delinking', 'email_retrieve'));
        $this->assertSame(6000, ServicePrice::amountFor('personalization', 'tracking'));
        $this->assertTrue(ServiceAvailability::isEnabled('nin-modification'));
        $this->assertTrue(ServiceAvailability::isUnavailable('nin-modification'));
        $this->assertFalse(ServiceAvailability::isEnabled('bvn-modification'));
        Livewire::test(Prices::class)->assertSet('unavailableServices.nin-modification', true)->assertSet('enabledServices.bvn-modification', false);
    }

    public function test_unavailable_service_stays_visible_with_danger_notice_and_blocks_an_open_form(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        $this->actingAs($user);
        $form = Livewire::test(BvnModification::class);
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('bvn-modification'), 'value' => '1']);
        $this->get(route('dashboard'))->assertOk()->assertSee('BVN MODIFICATION')->assertSee('Temporarily unavailable')->assertSee('bg-red-50');
        $this->get(route('bvn.modification'))->assertOk()->assertSee('Temporarily unavailable. Please try again later.')
            ->assertSee('type="submit" disabled', false)->assertDontSee('fieldset disabled', false);
        $form->call('submit')->assertHasErrors('serviceAvailability')->assertSee('temporarily unavailable');
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        SiteSetting::query()->where('key', ServiceAvailability::unavailableSettingKey('bvn-modification'))->update(['value' => '0']);
        $this->get(route('bvn.modification'))->assertOk()->assertDontSee('Temporarily unavailable. Please try again later.');
    }

    #[TestWith(['nin-modification', 'nin.modification', NinModification::class])]
    #[TestWith(['bvn-modification', 'bvn.modification', BvnModification::class])]
    #[TestWith(['personalization', 'personalization', Personalization::class])]
    public function test_disabling_new_services_hides_them_and_blocks_direct_routes(string $service, string $route, string $component): void
    {
        SiteSetting::create(['key' => ServiceAvailability::settingKey($service), 'value' => '1']);
        $this->actingAs(User::factory()->create());
        $response = $this->get(route('dashboard'))->assertOk()->assertDontSee('href="'.route($route).'"', false);
        $response->assertDontSee('card-'.$service, false)->assertDontSee('nav-'.$service, false);
        $this->get(route($route))->assertRedirect(route('dashboard'));
        Livewire::test($component)->assertStatus(503);
    }

    #[TestWith(['first', 15000])]
    #[TestWith(['second', 35000])]
    public function test_nin_modification_saves_selected_inputs_photo_and_one_combined_charge(string $stage, int $price): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        ServicePrice::factory()->create(['service' => 'nin-modification', 'method' => $stage.'_name', 'amount' => $price]);
        ServicePrice::factory()->create(['service' => 'nin-modification', 'method' => $stage.'_phone', 'amount' => 5000]);
        $user = User::factory()->create(['wallet_balance' => 100000]);
        $this->actingAs($user);
        $component = Livewire::test(NinModification::class, ['stage' => $stage])
            ->set('types', ['phone', 'name'])->set('nin', '01234567890')
            ->set('details', ['first_name' => 'Taylor', 'surname' => 'Example', 'phone' => '08165072514',
                ...($stage === 'second' ? ['old_first_name' => 'Alex', 'old_surname' => 'Example'] : [])])
            ->set('photo', UploadedFile::fake()->image('photo.jpg'))
            ->call('submit')->assertHasNoErrors()->assertSee('Submission successful.');
        $record = ServiceRequest::query()->sole();
        $this->assertSame('nin-modification', $record->service);
        $this->assertSame($stage, $record->method);
        $this->assertSame('pending', $record->status);
        $this->assertSame('name, phone', $record->payload['types']);
        $this->assertSame('Taylor', $record->payload['first_name']);
        $this->assertSame($stage === 'second' ? 'Alex' : null, $record->payload['old_first_name'] ?? null);
        $this->assertSame($price + 5000, $record->quoted_price);
        $this->assertSame(100000 - $price - 5000, $user->fresh()->wallet_balance);
        $this->assertStringNotContainsString('01234567890', $record->getRawOriginal('payload'));
        Storage::disk('local')->assertExists($record->screenshot_path);
        $component->call('submit')->assertHasNoErrors();
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertNothingSent();
        $this->actingAs(User::factory()->administrator()->create());
        $this->get(route('admin.requests.show', ['requestId' => $record->id]))->assertOk()->assertSee('Taylor')->assertSee('08165072514');
        $this->get(route('service-requests.proof', $record))->assertOk();
        $this->actingAs(User::factory()->create());
        $this->get(route('service-requests.proof', $record))->assertNotFound();
    }

    public function test_bvn_bank_and_types_determine_combined_price_and_admin_inputs(): void
    {
        ServicePrice::factory()->create(['service' => 'bvn-modification', 'method' => 'access_dob', 'amount' => 15000]);
        ServicePrice::factory()->create(['service' => 'bvn-modification', 'method' => 'access_phone', 'amount' => 2500]);
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $this->actingAs($user);
        Http::preventStrayRequests();
        $form = Livewire::test(BvnModification::class)->set('kind', 'access')->set('types', ['phone', 'dob'])
            ->set('bvn', '22484232222')->set('nin', '12345678901')->set('dob', '1990-01-01')->set('phone', '08165072514')
            ->call('submit')->assertHasNoErrors()->assertSee('Submission successful.');
        $record = ServiceRequest::query()->sole();
        $this->assertSame('access', $record->method);
        $this->assertSame(['bvn' => '22484232222', 'nin' => '12345678901', 'types' => 'dob, phone', 'dob' => '1990-01-01', 'phone' => '08165072514'], $record->payload);
        $this->assertSame(17500, $record->quoted_price);
        $this->assertSame(32500, $user->fresh()->wallet_balance);
        $form->call('submit')->assertHasNoErrors();
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertNothingSent();
        $this->actingAs(User::factory()->administrator()->create());
        $this->get(route('admin.requests.show', ['requestId' => $record->id]))->assertOk()->assertSee('Access Bank')->assertSee('1990-01-01')->assertSee('22484232222');
    }

    #[TestWith(['v1'])]
    #[TestWith(['v2'])]
    #[TestWith(['v3'])]
    public function test_demographics_save_for_admin_review_without_calling_provider(string $version): void
    {
        ServicePrice::factory()->create(['service' => 'nin-'.$version, 'method' => 'demographics', 'amount' => 12000]);
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $this->actingAs($user);
        Http::preventStrayRequests();
        $form = Livewire::test(NinVerification::class, ['version' => $version])->set('method', 'demographics')
            ->set('first_name', 'Taylor')->set('surname', 'Example')->set('dob', '1990-01-01')
            ->set('gender', 'female')->set('consent', true)->call('verify')
            ->assertHasNoErrors()->assertSee('saved for admin review');
        $record = ServiceRequest::query()->sole();
        $this->assertSame('nin-'.$version, $record->service);
        $this->assertSame('demographics', $record->method);
        $this->assertSame('Taylor', $record->payload['first_name']);
        $this->assertSame(12000, $record->quoted_price);
        $this->assertSame(38000, $user->fresh()->wallet_balance);
        $form->call('verify')->assertHasNoErrors();
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertNothingSent();
    }

    #[TestWith([null, 100000])]
    #[TestWith([20000, 10000])]
    public function test_missing_selected_type_price_or_insufficient_funds_saves_nothing(?int $price, int $balance): void
    {
        if ($price !== null) {
            ServicePrice::factory()->create(['service' => 'bvn-modification', 'method' => 'agency_dob', 'amount' => $price]);
        }
        $user = User::factory()->create(['wallet_balance' => $balance]);
        $this->actingAs($user);
        Livewire::test(BvnModification::class)->set('kind', 'agency')->set('types', ['dob'])
            ->set('bvn', '22484232222')->set('nin', '12345678901')->set('dob', '1990-01-01')
            ->call('submit')->assertHasErrors('serviceAvailability');
        $this->assertSame($balance, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_personalization_charges_once_and_status_checks_do_not_resubmit_or_recharge(): void
    {
        config(['services.tracking_id.tracking_url' => 'https://tracking.example.test', 'services.tracking_id.tracking_token' => 'token']);
        ServicePrice::factory()->create(['service' => 'personalization', 'method' => 'tracking', 'amount' => 15000]);
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $this->actingAs($user);
        Http::preventStrayRequests();
        $body = ['success' => true, 'id' => 12345, 'tracking_id' => 'S7Y0OG319000HEM', 'status' => 'In-Progress'];
        Http::fake([
            'https://tracking.example.test/api/personalization' => Http::response($body),
            'https://tracking.example.test/api/personalization/check' => Http::response([...$body, 'status' => 'Failed']),
        ]);
        $form = Livewire::test(Personalization::class)->set('trackingId', 'S7Y0OG319000HEM')->call('submit')->assertHasNoErrors()->assertSee('submission successful');
        $this->assertSame(35000, $user->fresh()->wallet_balance);
        Http::assertSentCount(1);
        $form->call('submit')->assertHasNoErrors();
        Http::assertSentCount(1);
        $record = PersonalizationRequest::query()->sole();
        $form->call('checkResult', $record->reference)->assertHasNoErrors()->assertSet('notice', 'Failed');
        $this->assertSame(50000, $user->fresh()->wallet_balance);
        $charge = WalletTransaction::query()->where('type', 'service_debit')->sole();
        $this->assertSame(15000, $charge->refund()->sole()->amount);
        $this->travel(11)->seconds();
        $form->call('checkResult', $record->reference)->assertHasNoErrors();
        $this->assertSame(50000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 2);
        Http::assertSentCount(3);
    }

    #[TestWith([null])]
    #[TestWith([60000])]
    public function test_personalization_with_unset_or_unaffordable_price_never_calls_provider(?int $price): void
    {
        config(['services.tracking_id.tracking_url' => 'https://tracking.example.test', 'services.tracking_id.tracking_token' => 'token']);
        if ($price !== null) {
            ServicePrice::factory()->create(['service' => 'personalization', 'method' => 'tracking', 'amount' => $price]);
        }
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $this->actingAs($user);
        Http::preventStrayRequests();
        Livewire::test(Personalization::class)->set('trackingId', 'S7Y0OG319000HEM')->call('submit')->assertHasErrors('personalization');
        $this->assertSame(50000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('personalization_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        Http::assertNothingSent();
    }

    public function test_unpriced_type_in_a_combination_prevents_partial_charging(): void
    {
        ServicePrice::factory()->create(['service' => 'bvn-modification', 'method' => 'agency_dob', 'amount' => 10000]);
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $this->actingAs($user);
        Livewire::test(BvnModification::class)->set('kind', 'agency')->set('types', ['dob', 'phone'])
            ->set('nin', '12345678901')->set('bvn', '22484232222')->set('dob', '1990-01-01')->set('phone', '08165072514')
            ->call('submit')->assertHasErrors('serviceAvailability');
        $this->assertSame(50000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_unavailable_personalization_blocks_api_and_wallet_changes(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        $this->actingAs($user);
        $form = Livewire::test(Personalization::class);
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('personalization'), 'value' => '1']);
        Http::preventStrayRequests();
        $form->set('trackingId', 'S7Y0OG319000HEM')->call('submit')->assertHasErrors('serviceAvailability');
        $this->assertDatabaseCount('personalization_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        Http::assertNothingSent();
    }
}
