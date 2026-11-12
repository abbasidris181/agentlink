<?php

namespace App\Livewire\Auth;

use App\Models\SiteSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public function login(): void
    {
        abort_if(Auth::check(), 403);

        $this->email = mb_strtolower(trim($this->email));
        $rateLimitKey = 'login:'.hash('sha256', $this->email.'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many sign-in attempts. Please try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($rateLimitKey),
                ]),
            ]);
        }

        $credentials = $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:72'],
        ]);

        $loginDisabled = SiteSetting::enabled('login_disabled');
        $credentials['is_active'] = true;
        if ($loginDisabled) {
            $credentials['role'] = 'super_admin';
        }

        if (! Auth::attempt($credentials)) {
            RateLimiter::hit($rateLimitKey, 60);
            $this->reset('password');

            throw ValidationException::withMessages(['email' => $loginDisabled ? 'User login is temporarily disabled. Only super administrators can sign in.' : __('auth.failed')]);
        }

        RateLimiter::clear($rateLimitKey);
        session()->regenerate();
        Auth::user()->forceFill(['last_login_at' => now()])->save();
        $this->reset('password');

        $this->redirectIntended(route(Auth::user()->needsEmailVerification() ? 'verification.notice' : 'dashboard'));
    }

    public function render(): View
    {
        return view('livewire.auth.login');
    }
}
