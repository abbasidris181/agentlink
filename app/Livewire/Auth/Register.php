<?php

namespace App\Livewire\Auth;

use App\Models\SiteSetting;
use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class Register extends Component
{
    public string $first_name = '';

    public string $surname = '';

    public string $phone = '';

    public string $business = '';

    public string $address = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register(): void
    {
        abort_if(Auth::check(), 403);
        if (SiteSetting::enabled('registration_disabled')) {
            throw ValidationException::withMessages(['email' => 'New user registration is temporarily disabled.']);
        }

        $rateLimitKey = 'registration:'.request()->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many registration attempts. Please try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($rateLimitKey),
                ]),
            ]);
        }

        RateLimiter::hit($rateLimitKey, 60);

        $this->first_name = trim($this->first_name);
        $this->surname = trim($this->surname);
        $this->phone = trim($this->phone);
        $this->business = trim($this->business);
        $this->address = trim($this->address);
        $this->email = mb_strtolower(trim($this->email));

        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'surname' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9\s().-]{5,28}[0-9]$/'],
            'business' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:1000'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:72',
                'confirmed',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (strlen($value) > 72) {
                        $fail('The password must not exceed 72 bytes.');
                    }
                },
            ],
        ]);

        $user = new User([
            ...$validated,
            'name' => $validated['first_name'].' '.$validated['surname'],
        ]);

        $user->requires_email_verification = SiteSetting::enabled('email_verification_enabled');
        $user->save();

        $canSignIn = ! SiteSetting::enabled('login_disabled');
        if ($canSignIn) {
            Auth::login($user);
            session()->regenerate();
        }

        try {
            event(new Registered($user));
        } catch (TransportExceptionInterface $exception) {
            report($exception);
            session()->flash('email_delivery_failed', true);
        }

        $this->reset('password', 'password_confirmation');

        session()->flash('status', $canSignIn ? 'Your account has been created. You are now signed in.' : 'Your account has been created. User login is temporarily disabled.');

        $this->redirectRoute(! $canSignIn ? 'home' : ($user->needsEmailVerification() ? 'verification.notice' : 'dashboard'));
    }

    public function render(): View
    {
        return view('livewire.auth.register');
    }
}
