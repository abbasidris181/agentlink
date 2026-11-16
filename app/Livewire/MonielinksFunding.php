<?php

namespace App\Livewire;

use App\Models\MonielinksAccount;
use App\Models\MonielinksPayment;
use App\MonielinksFundingService;
use App\MonielinksSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class MonielinksFunding extends Component
{
    public string $notice = '';

    public function boot(): void
    {
        $user = Auth::user()?->fresh();
        abort_unless($user && $user->is_active && ! $user->needsEmailVerification(), 403);
    }

    public function createAccount(MonielinksFundingService $funding): void
    {
        $key = 'monielinks:create:'.Auth::id();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['funding' => 'Please wait a minute before trying again.']);
        }
        RateLimiter::hit($key, 60);
        $account = $funding->reserve(Auth::user());
        $this->notice = $account->status === 'active' ? 'Your Monielinks account is ready.' : ($account->last_error ?? 'Your account request is being processed.');
    }

    public function render(): View
    {
        $settings = MonielinksSettings::get();

        return view('livewire.monielinks-funding', [
            'account' => MonielinksAccount::query()->where('user_id', Auth::id())->first(),
            'available' => $settings['enabled'] && $settings['client_id'] !== '' && $settings['secret_key'] !== '',
            'fundingCharge' => $settings['funding_charge'],
            'payments' => MonielinksPayment::query()->whereHas('account', fn ($query) => $query->where('user_id', Auth::id()))
                ->where('status', '!=', 'unverified')->latest('id')->limit(5)->get(),
        ]);
    }
}
