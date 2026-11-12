<?php

namespace App\Livewire;

use App\Money;
use App\WalletTransfer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class WalletTransferForm extends Component
{
    public string $recipient = '';

    public string $amount = '';

    #[Locked]
    public string $requestKey = '';

    #[Locked]
    public ?int $recipientId = null;

    #[Locked]
    public string $recipientName = '';

    #[Locked]
    public string $reviewedRecipient = '';

    #[Locked]
    public string $reviewedAmount = '';

    #[Locked]
    public string $completedReference = '';

    public function boot(): void
    {
        Gate::authorize('wallet.transfer');
    }

    public function mount(): void
    {
        $this->requestKey = (string) Str::uuid();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['recipient', 'amount'], true)) {
            $this->recipientId = null;
        }
    }

    public function preview(WalletTransfer $transfer): void
    {
        Gate::authorize('wallet.transfer');
        $this->recipientId = null;
        $key = 'wallet-lookup:'.Auth::id();
        if (RateLimiter::tooManyAttempts($key, 15)) {
            $this->addError('recipient', 'Too many lookups. Try again in a minute.');

            return;
        }
        RateLimiter::hit($key, 60);
        $this->amount = trim($this->amount);
        $this->validate(['amount' => ['required', 'regex:'.Money::INPUT_PATTERN]]);
        if (Money::parse($this->amount) < 1) {
            $this->addError('amount', 'Enter an amount greater than zero.');

            return;
        }
        $recipient = $transfer->recipient(Auth::user(), $this->recipient);
        $this->recipientId = $recipient->id;
        $this->recipientName = $recipient->name;
        $this->reviewedRecipient = $this->recipient;
        $this->reviewedAmount = $this->amount;
        $this->completedReference = '';
    }

    public function confirm(WalletTransfer $transfer): void
    {
        Gate::authorize('wallet.transfer');
        if ($this->recipientId === null || $this->recipient !== $this->reviewedRecipient || $this->amount !== $this->reviewedAmount) {
            $this->addError('recipient', 'Review the recipient and amount before confirming.');

            return;
        }
        $recipient = $transfer->recipient(Auth::user(), $this->recipient);
        if ($recipient->id !== $this->recipientId || $recipient->name !== $this->recipientName) {
            $this->recipientId = null;
            $this->addError('recipient', 'Recipient details changed. Review the transfer again.');

            return;
        }
        $transaction = $transfer->send(Auth::user(), $this->recipientId, $this->amount, $this->requestKey);
        $this->completedReference = $transaction->transfer_reference;
        $this->recipientId = null;
        $this->reset('recipient', 'amount');
        $this->requestKey = (string) Str::uuid();
    }

    public function render(): View
    {
        return view('livewire.wallet-transfer-form', ['balance' => Auth::user()->fresh()->wallet_balance]);
    }
}
