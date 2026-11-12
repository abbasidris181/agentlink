<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_welcome_page_renders_livewire_sign_in(): void
    {
        $this->get(route('home'))->assertOk()->assertSeeLivewire(Login::class);
    }

    public function test_valid_credentials_sign_the_user_in_and_redirect_to_dashboard(): void
    {
        $user = User::factory()->create(['email' => 'taylor@example.com']);
        $this->get(route('home'));
        $previousSessionId = session()->getId();

        Livewire::test(Login::class)->set('email', '  TAYLOR@example.com  ')
            ->set('password', 'password')->call('login')
            ->assertHasNoErrors()->assertSet('password', '')->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($previousSessionId, session()->getId());
    }

    public function test_invalid_credentials_do_not_sign_the_user_in(): void
    {
        User::factory()->create(['email' => 'taylor@example.com']);

        Livewire::test(Login::class)->set('email', 'taylor@example.com')
            ->set('password', 'incorrect')->call('login')
            ->assertHasErrors(['email'])->assertSee('These credentials do not match our records.')
            ->assertSet('password', '');

        $this->assertGuest();
    }

    public function test_missing_and_invalid_fields_are_rejected(): void
    {
        Livewire::test(Login::class)->call('login')
            ->assertHasErrors(['email' => 'required', 'password' => 'required'])
            ->set('email', 'invalid-email')->set('password', 'password')->call('login')
            ->assertHasErrors(['email' => 'email']);

        $this->assertGuest();
    }

    public function test_repeated_failed_sign_in_attempts_are_rate_limited(): void
    {
        $component = Livewire::test(Login::class)->set('email', 'unknown@example.com');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component->set('password', 'incorrect')->call('login')->assertHasErrors(['email']);
        }

        $component->set('password', 'incorrect')->call('login')
            ->assertHasErrors(['email'])->assertSee('Too many sign-in attempts.');

        $this->assertGuest();
    }

    public function test_signed_in_users_cannot_switch_accounts_through_the_login_action(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(Login::class)->call('login')->assertForbidden();

        $this->assertAuthenticatedAs($user);
    }
}
