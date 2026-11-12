<?php

namespace Tests\Feature;

use App\Livewire\BvnModification;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BvnModificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_bvn_modification_page_requires_sign_in_and_lists_all_bank_kinds(): void
    {
        $this->get(route('bvn.modification'))->assertRedirect(route('home'));
        Livewire::test(BvnModification::class)->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk()->assertSee('href="'.route('bvn.modification').'"', false);
        $this->get(route('bvn.modification'))->assertOk()->assertSeeLivewire(BvnModification::class)
            ->assertSee('BVN modification')->assertSee('Kind')->assertSee('Select one or more modification types.')
            ->assertSeeInOrder(['Agency Bvn', 'Access Bank', 'Uba Bank', 'Zenith Bank', 'First Bank', 'GTBank',
                'Fidelity Bank', 'Stanbic IBTC Bank', 'FCMB Bank', 'Wema Bank', 'Sterling Bank',
                'Microfinance banks', 'Jaiz Bank', 'Taj Bank']);
    }

    #[TestWith([['name']])]
    #[TestWith([['dob']])]
    #[TestWith([['phone']])]
    #[TestWith([['name', 'dob']])]
    #[TestWith([['name', 'phone']])]
    #[TestWith([['dob', 'phone']])]
    #[TestWith([['name', 'dob', 'phone']])]
    public function test_all_type_combinations_merge_inputs_without_duplicating_identifiers(array $types): void
    {
        foreach (['name', 'dob', 'phone'] as $type) {
            ServicePrice::factory()->create(['service' => 'bvn-modification', 'method' => 'agency_'.$type, 'amount' => 0]);
        }
        $user = User::factory()->create(['wallet_balance' => 100000]);
        $this->actingAs($user);
        Http::preventStrayRequests();
        $component = Livewire::test(BvnModification::class)->set('kind', 'agency')->set('types', $types);
        $html = $component->html();
        $this->assertSame(1, substr_count($html, 'id="bvn-modification-bvn"'));
        $this->assertSame(1, substr_count($html, 'id="bvn-modification-nin"'));
        foreach (['surname' => 'name', 'firstName' => 'name', 'middleName' => 'name', 'dob' => 'dob', 'phone' => 'phone'] as $field => $type) {
            $this->assertSame(in_array($type, $types, true) ? 1 : 0, substr_count($html, 'id="bvn-modification-'.$field.'"'));
        }
        foreach ($this->details() as $field => $value) {
            $component->set($field, $value);
        }
        $component->call('submit')->assertHasNoErrors()->assertSet('showAvailability', true)->assertSee('saved for admin review');
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('service_requests', 1);
        Http::assertNothingSent();
    }

    #[TestWith(['agency'])]
    #[TestWith(['access'])]
    #[TestWith(['uba'])]
    #[TestWith(['zenith'])]
    #[TestWith(['first'])]
    #[TestWith(['gtbank'])]
    #[TestWith(['fidelity'])]
    #[TestWith(['stanbic'])]
    #[TestWith(['fcmb'])]
    #[TestWith(['wema'])]
    #[TestWith(['sterling'])]
    #[TestWith(['microfinance'])]
    #[TestWith(['jaiz'])]
    #[TestWith(['taj'])]
    public function test_every_listed_bank_kind_can_be_used(string $kind): void
    {
        ServicePrice::factory()->create(['service' => 'bvn-modification', 'method' => $kind.'_dob', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        Livewire::test(BvnModification::class)->set('kind', $kind)->set('types', ['dob'])
            ->set('bvn', '01234567890')->set('nin', '12345678901')->set('dob', '1990-01-01')
            ->call('submit')->assertHasNoErrors();
    }

    public function test_name_modification_requires_all_three_names(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(BvnModification::class)->set('kind', 'access')->set('types', ['name'])
            ->call('submit')->assertHasErrors(['bvn', 'nin', 'surname', 'firstName', 'middleName'])
            ->assertHasNoErrors(['dob', 'phone']);
    }

    #[TestWith(['kind', '', 'kind'])]
    #[TestWith(['kind', 'invalid', 'kind'])]
    #[TestWith(['types', [], 'types'])]
    #[TestWith(['types', ['invalid'], 'types.0'])]
    #[TestWith(['types', ['name', 'name'], 'types.0'])]
    #[TestWith(['bvn', '1234567890', 'bvn'])]
    #[TestWith(['nin', 'abcdefghijk', 'nin'])]
    #[TestWith(['surname', '', 'surname'])]
    #[TestWith(['firstName', '', 'firstName'])]
    #[TestWith(['middleName', '', 'middleName'])]
    #[TestWith(['dob', '2999-01-01', 'dob'])]
    #[TestWith(['dob', '1990-02-30', 'dob'])]
    #[TestWith(['phone', 'invalid', 'phone'])]
    public function test_invalid_required_inputs_are_rejected(string $field, mixed $value, string $error): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(BvnModification::class)->set('kind', 'agency')->set('types', ['name', 'dob', 'phone']);
        foreach ($this->details() as $key => $detail) {
            $component->set($key, $detail);
        }
        $component->set($field, $value)->call('submit')->assertHasErrors([$error])->assertSet('showAvailability', false);
    }

    public function test_adding_types_preserves_values_and_deselecting_only_clears_removed_fields(): void
    {
        foreach (['name', 'dob', 'phone'] as $type) {
            ServicePrice::factory()->create(['service' => 'bvn-modification', 'method' => 'agency_'.$type, 'amount' => 0]);
        }
        $this->actingAs(User::factory()->create());
        Livewire::test(BvnModification::class)->set('kind', 'agency')->set('types', ['name'])
            ->set('bvn', '01234567890')->set('nin', '12345678901')
            ->set('surname', 'Example')->set('firstName', 'Taylor')->set('middleName', 'Alex')
            ->set('types', ['name', 'phone'])->assertSet('surname', 'Example')->assertSet('firstName', 'Taylor')->assertSet('middleName', 'Alex')
            ->set('phone', '+234 816 507 2514')->set('types', ['name', 'phone', 'dob'])->set('dob', '1990-01-01')
            ->call('submit')->assertHasNoErrors()->assertSet('showAvailability', true)->assertSet('phone', '+2348165072514')
            ->set('types', ['phone'])->assertSet('surname', '')->assertSet('firstName', '')->assertSet('middleName', '')->assertSet('dob', '')
            ->assertSet('phone', '+2348165072514')->assertSet('bvn', '01234567890')->assertSet('nin', '12345678901')
            ->assertSet('showAvailability', false)->assertHasNoErrors()
            ->call('submit')->assertHasNoErrors();
    }

    public function test_inactive_users_cannot_submit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $component = Livewire::test(BvnModification::class);
        $user->forceFill(['is_active' => false])->save();
        $component->call('submit')->assertForbidden();
    }

    public function test_signed_out_users_cannot_submit(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(BvnModification::class);
        Auth::logout();
        $component->call('submit')->assertForbidden();
    }

    private function details(): array
    {
        return [
            'bvn' => '01234567890', 'nin' => '12345678901',
            'surname' => 'Example', 'firstName' => 'Taylor', 'middleName' => 'Alex',
            'dob' => '1990-01-01', 'phone' => '08165072514',
        ];
    }
}
