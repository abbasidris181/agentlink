<?php

namespace App\Livewire;

use App\Jobs\VerifyMonnifyPayment;
use App\Models\MonnifyAccount;
use App\Models\MonnifyPayment;
use App\MonielinksSettings;
use App\MonnifyFundingService;
use App\MonnifySettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class WalletFunding extends Component
{
    public string $bvn = '';

    public string $nin = '';

    public bool $consent = false;

    public string $notice = '';

    public function boot(): void
    {
        $user = Auth::user()?->fresh();
        abort_unless($user && $user->is_active && ! $user->needsEmailVerification(), 403);
    }

    public function createAccount(MonnifyFundingService $funding): void
    {
        $this->limit('create', 3);
        $account = $funding->reserve(Auth::user(), trim($this->bvn), trim($this->nin), $this->consent);
        $this->reset('bvn', 'nin');
        $this->notice = $account->status === 'active' ? 'Your reserved account is ready.' : ($account->last_error ?? 'Account creation is in progress. Check account status shortly.');
    }

    public function checkAccount(MonnifyFundingService $funding): void
    {
        $this->limit('check-account', 5);
        $account = $funding->refreshAccount(Auth::user());
        $this->notice = $account?->status === 'active' ? 'Your reserved account is ready.' : ($account?->last_error ?? 'No reserved account was found.');
    }

    public function checkPayments(): void
    {
        $this->limit('check-payments', 2);
        $payments = MonnifyPayment::query()->whereHas('account', fn ($query) => $query->where('user_id', Auth::id()))
            ->whereIn('status', ['pending', 'review'])->latest('id')->limit(5)->get();
        foreach ($payments as $payment) {
            VerifyMonnifyPayment::dispatch($payment->id);
        }
        $this->notice = $payments->isEmpty() ? 'There are no pending payments to check.' : 'Payment checks queued. The status will update after provider verification.';
    }

    private function limit(string $action, int $maximum): void
    {
        $key = 'monnify:'.$action.':'.Auth::id();
        if (RateLimiter::tooManyAttempts($key, $maximum)) {
            throw ValidationException::withMessages(['funding' => 'Please wait a minute before trying again.']);
        }
        RateLimiter::hit($key, 60);
    }

    public function render(): View
    {
        $settings = MonnifySettings::get();
        $account = MonnifyAccount::query()->where('user_id', Auth::id())->where('mode', $settings['mode'])->where('contract_code', $settings['contract_code'])->first();
        $available = $settings['enabled'] && $settings['api_key'] !== '' && $settings['secret_key'] !== '' && $settings['contract_code'] !== '';
        $monielinks = MonielinksSettings::get();

        return view('livewire.wallet-funding', [
            'account' => $account, 'available' => $available, 'mode' => $settings['mode'], 'fundingCharge' => $settings['funding_charge'],
            'monielinksAvailable' => $monielinks['enabled'] && $monielinks['client_id'] !== '' && $monielinks['secret_key'] !== '',
            'payments' => MonnifyPayment::query()->whereHas('account', fn ($query) => $query->where('user_id', Auth::id()))->latest('id')->limit(5)->get(),
        ]);
    }
}
