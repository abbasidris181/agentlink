<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_are_redirected_to_the_welcome_page(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('home'));
        Livewire::test(Dashboard::class)->assertForbidden();
    }

    public function test_signed_in_users_see_all_services_and_their_own_profile(): void
    {
        $user = User::factory()->create(['first_name' => 'Taylor', 'business' => 'Example Business']);
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertOk()->assertSeeLivewire(Dashboard::class)
            ->assertSee('NIN-V1')->assertSee('NIN-V2')->assertSee('NIN-V3')
            ->assertSee('BVN VERIFICATION')->assertSee('href="'.route('bvn.verify').'"', false)->assertSee('IPE CLEARING')->assertSee('INSTANT-IPE')
            ->assertSee('href="'.route('ipe.clearing').'"', false)
            ->assertSee('href="'.route('ipe.mod').'"', false)
            ->assertSee('href="'.route('ipe.instant').'"', false)
            ->assertSee('BVN RETRIEVAL')->assertSee('href="'.route('bvn.retrieval').'"', false)
            ->assertSee('href="'.route('validations').'"', false)
            ->assertSee('href="'.route('delinking').'"', false)
            ->assertSee('MOD-IPE')->assertSee('VALIDATIONS')->assertSee('Self-Service')
            ->assertSee('PERSONALIZATION')->assertSee('NIN MODIFICATION')->assertSee('BVN MODIFICATION')
            ->assertSee('Welcome back, Taylor.')->assertSee('Example Business');
        $this->get(route('home'))->assertRedirect(route('dashboard'));
        $this->get(route('register'))->assertRedirect(route('dashboard'));
    }

    public function test_dashboard_lists_all_services_without_search_controls(): void
    {
        $this->actingAs(User::factory()->create());
        $response = $this->get(route('dashboard'))->assertOk()
            ->assertDontSee('id="service-search"', false)
            ->assertSee('wire:key="card-nin-v1"', false)
            ->assertSee('wire:key="card-bvn-verification"', false)
            ->assertSee('wire:key="card-delinking"', false);

        $this->assertSame(14, preg_match_all('/wire:key="card-/', $response->getContent()));
    }

    #[TestWith(['nin-v1', 'NIN-V1'])]
    #[TestWith(['nin-v2', 'NIN-V2'])]
    #[TestWith(['nin-v3', 'NIN-V3'])]
    #[TestWith(['bvn-verification', 'BVN VERIFICATION'])]
    #[TestWith(['bvn retrival', 'BVN RETRIEVAL'])]
    #[TestWith(['ipe-clearing', 'IPE CLEARING'])]
    #[TestWith(['instant-ipe', 'INSTANT-IPE'])]
    #[TestWith(['mod-ipe', 'MOD-IPE'])]
    #[TestWith(['validations', 'VALIDATIONS'])]
    #[TestWith(['delinking', 'Self-Service'])]
    #[TestWith(['personalization', 'PERSONALIZATION'])]
    #[TestWith(['nin-modification', 'NIN MODIFICATION'])]
    #[TestWith(['bvn-modification', 'BVN MODIFICATION'])]
    public function test_service_selection_displays_its_availability(string $serviceId, string $name): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(Dashboard::class)->call('selectService', $serviceId);

        if ($serviceId === 'bvn-modification') {
            $component->assertRedirect(route('bvn.modification'));

            return;
        }

        if ($serviceId === 'personalization') {
            $component->assertRedirect(route('personalization'));

            return;
        }

        if ($serviceId === 'nin-modification') {
            $component->assertRedirect(route('nin.modification'));

            return;
        }

        if ($serviceId === 'instant-ipe') {
            $component->assertRedirect(route('ipe.instant'));

            return;
        }

        if ($serviceId === 'delinking') {
            $component->assertRedirect(route('delinking'));

            return;
        }

        if ($serviceId === 'validations') {
            $component->assertRedirect(route('validations'));

            return;
        }

        if ($serviceId === 'ipe-clearing') {
            $component->assertRedirect(route('ipe.clearing'));

            return;
        }

        if ($serviceId === 'mod-ipe') {
            $component->assertRedirect(route('ipe.mod'));

            return;
        }

        if ($serviceId === 'bvn retrival') {
            $component->assertRedirect(route('bvn.retrieval'));

            return;
        }

        if ($serviceId === 'bvn-verification') {
            $component->assertRedirect(route('bvn.verify'));

            return;
        }

        if (in_array($serviceId, ['nin-v1', 'nin-v2', 'nin-v3'], true)) {
            $component->assertRedirect(route('nin.'.substr($serviceId, 4)));

            return;
        }

        $component
            ->assertSet('selectedServiceId', $serviceId)
            ->assertSee($name)->assertSee('This service is not available yet.')
            ->call('closeService')->assertSet('selectedServiceId', null)
            ->assertDontSee('This service is not available yet.');
    }

    public function test_nin_modification_page_requires_sign_in_and_displays_both_service_cards(): void
    {
        $this->get(route('nin.modification'))->assertRedirect(route('home'));
        $this->actingAs(User::factory()->create());
        $response = $this->get(route('nin.modification'))->assertOk()
            ->assertSee('NIN modification')->assertSee('First modification')->assertSee('Second modification')
            ->assertSee('wire:key="nin-modification-first"', false)->assertSee('wire:key="nin-modification-second"', false)
            ->assertSee('href="'.route('dashboard').'"', false)->assertDontSee('Explore services');
        $this->assertSame(2, preg_match_all('/wire:key="nin-modification-/', $response->getContent()));
    }

    public function test_unknown_services_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Dashboard::class)->call('selectService', 'unknown-service')->assertNotFound();
    }

    public function test_dashboard_actions_are_rejected_after_signing_out(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(Dashboard::class);
        Auth::logout();

        $component->call('selectService', 'nin-v1')->assertForbidden();
    }

    public function test_sign_out_invalidates_the_session_and_redirects_home(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'));
        session()->put('dashboard-session-marker', 'private');
        $previousToken = session()->token();

        Livewire::test(Dashboard::class)->call('logout')->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertFalse(session()->has('dashboard-session-marker'));
        $this->assertNotSame($previousToken, session()->token());
    }

    public function test_profile_content_is_escaped(): void
    {
        $user = User::factory()->create([
            'first_name' => '<script>alert(1)</script>',
            'business' => '<script>alert(2)</script>',
        ]);
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;script&gt;alert(2)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<script>alert(2)</script>', false);
    }
}
