<?php

namespace Tests\Feature;

use App\Livewire\Admin\Settings;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\VerifyEmail;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail as VerificationNotification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class AuthenticationSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function setting(string $key, bool $enabled): void
    {
        SiteSetting::updateOrCreate(['key' => $key], ['value' => $enabled ? '1' : '0']);
    }

    private function signup(): Testable
    {
        return Livewire::test(Register::class)->set('first_name', 'Taylor')->set('surname', 'Example')
            ->set('email', 'new@example.com')->set('phone', '08012345678')->set('business', 'Example Business')
            ->set('address', '123 Example Street')->set('password', 'Password123!')->set('password_confirmation', 'Password123!')
            ->call('register');
    }

    private function verificationUrl(User $user, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $user->id, 'hash' => sha1($user->email)]);
    }

    public function test_access_switches_are_saved_and_loaded_in_admin_settings(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Settings::class)->assertSet('emailVerificationEnabled', false)->assertSet('loginDisabled', false)
            ->assertSet('registrationDisabled', false)->set('emailVerificationEnabled', true)->set('loginDisabled', true)
            ->set('registrationDisabled', true)->call('save')->assertHasNoErrors();
        foreach (['email_verification_enabled', 'login_disabled', 'registration_disabled'] as $key) {
            $this->assertDatabaseHas('site_settings', ['key' => $key, 'value' => '1']);
        }
        Livewire::test(Settings::class)->assertSet('emailVerificationEnabled', true)->assertSet('loginDisabled', true)->assertSet('registrationDisabled', true);
        $this->assertDatabaseHas('admin_audits', ['event' => 'settings.updated']);
    }

    public function test_view_only_staff_cannot_change_access_switches(): void
    {
        $this->actingAs(User::factory()->staff(['settings.view'])->create());
        Livewire::test(Settings::class)->set('loginDisabled', true)->set('emailVerificationEnabled', true)->call('save')->assertForbidden();
        $this->assertDatabaseCount('site_settings', 0);
    }

    public function test_signup_sends_verification_and_blocks_services_when_enabled(): void
    {
        Notification::fake();
        $this->setting('email_verification_enabled', true);
        $this->signup()->assertHasNoErrors()->assertRedirect(route('verification.notice'));
        $user = User::query()->where('email', 'new@example.com')->sole();
        $this->assertTrue($user->requires_email_verification);
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerificationNotification::class);
        $this->get(route('verification.notice'))->assertOk()->assertSee('new@example.com')->assertSee('Resend verification email');
        foreach (['dashboard', 'nin.v1', 'nin.v2', 'nin.v3', 'bvn.verify', 'bvn.retrieval', 'ipe.clearing', 'ipe.mod', 'ipe.instant', 'validations', 'delinking', 'transactions'] as $route) {
            $this->get(route($route))->assertRedirect(route('verification.notice'));
        }
        $this->getJson(route('dashboard'))->assertForbidden()->assertJsonPath('message', 'Please verify your email address.');
    }

    public function test_signup_without_verification_does_not_send_email_or_mark_email_verified(): void
    {
        Notification::fake();
        $this->signup()->assertHasNoErrors()->assertRedirect(route('dashboard'));
        $user = User::query()->where('email', 'new@example.com')->sole();
        $this->assertFalse($user->requires_email_verification);
        $this->assertNull($user->email_verified_at);
        Notification::assertNothingSent();
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_a_signed_link_verifies_the_current_user_and_unlocks_services(): void
    {
        Event::fake([Verified::class]);
        $this->setting('email_verification_enabled', true);
        $user = User::factory()->unverified()->create(['requires_email_verification' => true]);
        $this->actingAs($user);
        $this->get($this->verificationUrl($user))->assertRedirect(route('dashboard'));
        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(Verified::class, fn (Verified $event): bool => $event->user->id === $user->id);
        $this->get(route('dashboard'))->assertOk();
        Livewire::test(VerifyEmail::class)->assertRedirect(route('dashboard'));
    }

    #[TestWith(['expired'])]
    #[TestWith(['tampered'])]
    #[TestWith(['other-user'])]
    #[TestWith(['wrong-email'])]
    public function test_invalid_verification_links_cannot_verify_an_account(string $case): void
    {
        $this->setting('email_verification_enabled', true);
        $user = User::factory()->unverified()->create(['requires_email_verification' => true]);
        $this->actingAs($user);
        $url = $this->verificationUrl($user, $case === 'expired' ? -1 : 60);
        if ($case === 'tampered') {
            $url .= '&tampered=1';
        }
        if ($case === 'other-user') {
            $url = $this->verificationUrl(User::factory()->unverified()->create());
        }
        if ($case === 'wrong-email') {
            $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('different@example.com')]);
        }
        $this->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resending_verification_is_authenticated_and_rate_limited(): void
    {
        Notification::fake();
        $this->get(route('verification.notice'))->assertRedirect(route('home'));
        $this->setting('email_verification_enabled', true);
        $user = User::factory()->unverified()->create(['requires_email_verification' => true]);
        $this->actingAs($user);
        $component = Livewire::test(VerifyEmail::class)->call('resend')->assertHasNoErrors()->assertSee('A new verification link');
        $component->call('resend')->assertHasErrors('verification');
        Notification::assertSentToTimes($user, VerificationNotification::class, 1);
        $this->travel(61)->seconds();
        $component->call('resend')->assertHasNoErrors();
        Notification::assertSentToTimes($user, VerificationNotification::class, 2);
    }

    public function test_existing_accounts_are_unaffected_and_disabling_verification_releases_pending_accounts(): void
    {
        $this->setting('email_verification_enabled', true);
        $existing = User::factory()->unverified()->create();
        $pending = User::factory()->unverified()->create(['requires_email_verification' => true]);
        $this->actingAs($existing)->get(route('dashboard'))->assertOk();
        $this->actingAs($pending)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $this->setting('email_verification_enabled', false);
        $this->get(route('dashboard'))->assertOk();
        $this->assertNull($pending->fresh()->email_verified_at);
    }

    public function test_verification_link_opened_while_logged_out_continues_after_login(): void
    {
        $this->setting('email_verification_enabled', true);
        $user = User::factory()->unverified()->create(['requires_email_verification' => true]);
        $url = $this->verificationUrl($user);
        $this->get($url)->assertRedirect(route('home'));
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('login')->assertRedirect($url);
        $this->get($url)->assertRedirect(route('dashboard'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_enabling_verification_also_blocks_an_already_open_livewire_service_page(): void
    {
        $user = User::factory()->unverified()->create(['requires_email_verification' => true]);
        $this->actingAs($user);
        $response = $this->get(route('dashboard'))->assertOk();
        preg_match('/wire:snapshot="([^"]+)"/', $response->getContent(), $match);
        $this->assertArrayHasKey(1, $match);
        $snapshot = html_entity_decode($match[1], ENT_QUOTES);
        $this->setting('email_verification_enabled', true);
        $this->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['method' => 'selectService', 'params' => ['nin-v1'], 'path' => '']]]],
        ], ['X-Livewire' => 'true'])->assertForbidden();
    }

    public function test_verification_requirement_cannot_be_disabled_through_mass_assignment(): void
    {
        $user = User::factory()->unverified()->create(['requires_email_verification' => true]);
        $user->fill(['requires_email_verification' => false, 'email_verified_at' => now()])->save();
        $this->assertTrue($user->fresh()->requires_email_verification);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_pending_users_are_redirected_to_verification_after_login(): void
    {
        $this->setting('email_verification_enabled', true);
        $user = User::factory()->unverified()->create(['requires_email_verification' => true]);
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('login')->assertRedirect(route('verification.notice'));
        $this->assertAuthenticatedAs($user);
    }

    #[TestWith(['users', false])]
    #[TestWith(['agent', false])]
    #[TestWith(['staff', false])]
    #[TestWith(['super_admin', true])]
    public function test_login_disable_blocks_every_role_except_super_admin(string $role, bool $allowed): void
    {
        $this->setting('login_disabled', true);
        $user = User::factory()->create(['role' => $role]);
        $form = Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('login');
        if ($allowed) {
            $form->assertHasNoErrors()->assertRedirect(route('dashboard'));
            $this->assertAuthenticatedAs($user);
        } else {
            $form->assertHasErrors('email')->assertSee('User login is temporarily disabled.');
            $this->assertGuest();
        }
    }

    public function test_login_can_be_reenabled_and_existing_sessions_are_not_terminated(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->setting('login_disabled', true);
        $this->get(route('dashboard'))->assertOk();
        Auth::logout();
        $this->setting('login_disabled', false);
        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('login')->assertRedirect(route('dashboard'));
    }

    public function test_registration_disable_hides_form_and_prevents_direct_submissions(): void
    {
        $this->setting('registration_disabled', true);
        $this->get(route('register'))->assertOk()->assertSee('New user registration is temporarily disabled.')->assertDontSee('wire:submit="register"', false);
        $this->get(route('home'))->assertOk()->assertDontSee('>Sign up<', false);
        $this->signup()->assertHasErrors('email');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
        $this->setting('registration_disabled', false);
        $this->signup()->assertHasNoErrors()->assertRedirect(route('dashboard'));
    }

    public function test_registration_cannot_bypass_the_login_disable_switch(): void
    {
        Notification::fake();
        $this->setting('login_disabled', true);
        $this->signup()->assertHasNoErrors()->assertRedirect(route('home'));
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
        $this->assertGuest();
    }

    public function test_mail_delivery_failure_preserves_the_account_and_allows_a_later_resend(): void
    {
        $this->setting('email_verification_enabled', true);
        Notification::partialMock()->shouldReceive('send')->once()->andThrow(new TransportException('Mailer unavailable'));
        $this->signup()->assertHasNoErrors()->assertRedirect(route('verification.notice'));
        $user = User::query()->where('email', 'new@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->requires_email_verification);
        Notification::fake();
        Livewire::test(VerifyEmail::class)->call('resend')->assertHasNoErrors();
        Notification::assertSentTo($user, VerificationNotification::class);
    }
}
