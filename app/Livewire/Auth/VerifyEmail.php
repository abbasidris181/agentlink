<?php

namespace App\Livewire\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class VerifyEmail extends Component
{
    public function boot(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function mount(): void
    {
        if (! Auth::user()->fresh()->needsEmailVerification()) {
            $this->redirectRoute('dashboard');
        }
    }

    public function resend(): void
    {
        $user = Auth::user()->fresh();
        if (! $user->needsEmailVerification()) {
            $this->redirectRoute('dashboard');

            return;
        }
        $key = 'email-verification:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->addError('verification', 'Please wait one minute before requesting another verification email.');

            return;
        }
        RateLimiter::hit($key, 60);
        try {
            $user->sendEmailVerificationNotification();
        } catch (TransportExceptionInterface $exception) {
            report($exception);
            $this->addError('verification', 'We could not send the email. Please try again later or contact support.');

            return;
        }
        session()->flash('status', 'A new verification link has been sent to your email address.');
    }

    public function logout(): void
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirectRoute('home');
    }

    public function render(): View
    {
        return view('livewire.auth.verify-email');
    }
}
