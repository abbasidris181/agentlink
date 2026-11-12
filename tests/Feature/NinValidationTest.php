<?php

namespace Tests\Feature;

use App\Livewire\NinValidation;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NinValidationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_cannot_access_validations(): void
    {
        $this->get(route('validations'))->assertRedirect(route('home'));
        Livewire::test(NinValidation::class)->assertForbidden();
    }

    public function test_signed_in_users_see_the_nin_input_and_validation_history(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('validations'))->assertOk()->assertSeeLivewire(NinValidation::class)
            ->assertSee('NIN number')->assertSee('Enter your 11-digit NIN')->assertSee('Submit validation')
            ->assertSeeInOrder(['Validation history', 'Transaction id', 'Input', 'Status', 'Date', 'Price', 'Reply', 'No validations yet'])
            ->assertDontSee('Welcome back,')->assertDontSee('Verification method');
    }

    #[TestWith(['01234567890', '01234567890'])]
    #[TestWith(['12345678901', '12345678901'])]
    #[TestWith([' 01234567890 ', '01234567890'])]
    public function test_valid_nin_preserves_digits_without_sending_an_api_request(string $nin, string $normalized): void
    {
        ServicePrice::factory()->create(['service' => 'validations', 'method' => 'nin', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();

        Livewire::test(NinValidation::class)->set('nin', $nin)->call('submit')
            ->assertHasNoErrors()->assertSet('nin', $normalized)->assertSet('showAvailability', true)
            ->assertSee('Request submitted for admin review.')->assertSee('Pending');

        Http::assertNothingSent();
    }

    #[TestWith(['', 'required'])]
    #[TestWith(['   ', 'required'])]
    #[TestWith(['1234567890', 'regex'])]
    #[TestWith(['123456789012', 'regex'])]
    #[TestWith(['1234567890a', 'regex'])]
    #[TestWith(['01234 567890', 'regex'])]
    public function test_invalid_nin_is_rejected(string $nin, string $rule): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(NinValidation::class)->set('nin', $nin)->call('submit')
            ->assertHasErrors(['nin' => $rule])->assertSet('showAvailability', false)
            ->assertSee($rule === 'required' ? 'Enter your 11-digit NIN number.' : 'Enter an 11-digit NIN number.');
    }

    public function test_invalid_resubmission_clears_the_availability_message(): void
    {
        ServicePrice::factory()->create(['service' => 'validations', 'method' => 'nin', 'amount' => 0]);
        $this->actingAs(User::factory()->create());

        Livewire::test(NinValidation::class)->set('nin', '01234567890')->call('submit')
            ->assertSet('showAvailability', true)
            ->set('nin', 'invalid')->call('submit')->assertHasErrors(['nin'])
            ->assertSet('showAvailability', false)->assertDontSee('Request submitted for admin review.');
    }

    public function test_submission_is_forbidden_after_sign_out(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(NinValidation::class);
        Auth::logout();

        $component->call('submit')->assertForbidden();
    }
}
