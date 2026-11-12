<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\IpeRequest;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class IpeRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['ipe.clearing'])]
    #[TestWith(['ipe.mod'])]
    public function test_guests_cannot_access_ipe_pages(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('home'));
    }

    #[TestWith(['ipe-clearing'])]
    #[TestWith(['mod-ipe'])]
    public function test_guests_cannot_use_ipe_components(string $service): void
    {
        Livewire::test(IpeRequest::class, ['service' => $service])->assertForbidden();
    }

    #[TestWith(['ipe.clearing', 'IPE Clearing'])]
    #[TestWith(['ipe.mod', 'MOD-IPE'])]
    public function test_signed_in_users_see_their_ipe_form_and_history(string $route, string $name): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route($route))->assertOk()->assertSeeLivewire(IpeRequest::class)
            ->assertSee($name)->assertSee('Tracking ID')
            ->assertSee('Enter your 15-character tracking ID')->assertSee('Submit request')
            ->assertSee($name.' request history')
            ->assertSeeInOrder(['Request history', 'Transaction id', 'Tracking id', 'Status', 'Date', 'Price', 'Reply', 'No requests yet'])
            ->assertDontSee('Welcome back,')->assertDontSee('Verification method');
    }

    #[TestWith(['ipe-clearing', '000ABC123def456', '000ABC123def456'])]
    #[TestWith(['mod-ipe', '000ABC123def456', '000ABC123def456'])]
    #[TestWith(['ipe-clearing', '  ABC123DEF456GH7  ', 'ABC123DEF456GH7'])]
    #[TestWith(['mod-ipe', '123456789012345', '123456789012345'])]
    public function test_valid_tracking_id_preserves_its_characters_without_submitting_an_api_request(string $service, string $trackingId, string $normalized): void
    {
        ServicePrice::factory()->create(['service' => $service, 'method' => 'tracking', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();

        Livewire::test(IpeRequest::class, ['service' => $service])->set('trackingId', $trackingId)
            ->call('submit')->assertHasNoErrors()->assertSet('trackingId', $normalized)
            ->assertSet('showAvailability', true)->assertSee('Request submitted for admin review.')
            ->assertSee('Pending');

        Http::assertNothingSent();
    }

    #[DataProvider('invalidTrackingIds')]
    public function test_invalid_tracking_ids_are_rejected(string $service, string $trackingId, string $rule): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(IpeRequest::class, ['service' => $service])->set('trackingId', $trackingId)
            ->call('submit')->assertHasErrors(['trackingId' => $rule])->assertSet('showAvailability', false)
            ->assertSee($rule === 'required' ? 'Enter your 15-character tracking ID.' : 'Enter exactly 15 letters or digits for the tracking ID.');
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidTrackingIds(): array
    {
        $cases = [];

        foreach (['ipe-clearing', 'mod-ipe'] as $service) {
            foreach ([
                'empty' => ['', 'required'],
                'blank' => ['   ', 'required'],
                'short' => ['ABC123DEF456GH', 'regex'],
                'long' => ['ABC123DEF456GH78', 'regex'],
                'symbol' => ['ABC123DEF456GH-', 'regex'],
                'internal space' => ['ABC123 DEF456GH', 'regex'],
                'non ASCII' => ['ABC123DEF456GH?', 'regex'],
            ] as $name => [$trackingId, $rule]) {
                $cases[$service.' '.$name] = [$service, $trackingId, $rule];
            }
        }

        return $cases;
    }

    #[TestWith(['ipe-clearing'])]
    #[TestWith(['mod-ipe'])]
    public function test_invalid_resubmission_clears_the_availability_message(string $service): void
    {
        ServicePrice::factory()->create(['service' => $service, 'method' => 'tracking', 'amount' => 0]);
        $this->actingAs(User::factory()->create());

        Livewire::test(IpeRequest::class, ['service' => $service])->set('trackingId', 'ABC123DEF456GH7')
            ->call('submit')->assertSet('showAvailability', true)
            ->set('trackingId', 'invalid')->call('submit')->assertHasErrors(['trackingId'])
            ->assertSet('showAvailability', false)->assertDontSee('Request submitted for admin review.');
    }

    public function test_unknown_ipe_services_are_not_found(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(IpeRequest::class, ['service' => 'instant-ipe'])->assertNotFound();
        Livewire::test(Dashboard::class, ['ipeService' => 'invalid'])->assertNotFound();
    }

    #[TestWith(['ipe-clearing'])]
    #[TestWith(['mod-ipe'])]
    public function test_ipe_submission_is_forbidden_after_sign_out(string $service): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(IpeRequest::class, ['service' => $service]);
        Auth::logout();

        $component->call('submit')->assertForbidden();
    }
}
