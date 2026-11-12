<?php

namespace App\Livewire;

use App\Models\BvnVerificationRecord;
use App\Models\ServicePrice;
use App\ServiceAvailability;
use App\VerificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class BvnVerification extends Component
{
    public string $bvn = '';

    public bool $consent = false;

    #[Locked]
    public bool $showAvailability = false;

    #[Locked]
    public string $requestKey = '';

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
        ServiceAvailability::assertEnabled('bvn-verification');
        $this->requestKey = (string) Str::uuid();
    }

    public function hydrate(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function verify(VerificationService $provider): void
    {
        abort_unless(Auth::check(), 403);
        $this->showAvailability = false;
        ServiceAvailability::validateSubmission('bvn-verification', 'bvn');

        $this->showAvailability = false;
        $this->bvn = trim($this->bvn);

        $this->validate([
            'consent' => ['accepted'],
            'bvn' => ['required', 'string', 'regex:/^[0-9]{11}$/'],
        ], [
            'consent.accepted' => 'Please give your consent before continuing with verification.',
            'bvn.required' => 'Enter your 11-digit BVN number.',
            'bvn.regex' => 'Enter an 11-digit BVN number.',
        ], [
            'bvn' => 'BVN',
        ]);

        $record = $provider->verify(Auth::user(), $this->bvn, $this->requestKey, 'bvn');
        if ($record->status === 'successful') {
            $this->redirectRoute('bvn.result', ['verification' => $record->reference]);
        } elseif ($record->status === 'failed') {
            $this->requestKey = (string) Str::uuid();
            $this->addError('verification', 'Verification could not be completed. Any verification charge has been returned to your wallet. Please check the BVN and try again.');
        } else {
            $this->addError('verification', 'This verification is still processing. Check your history before trying again.');
        }
    }

    public function render(): View
    {
        return view('livewire.bvn-verification', ['canSubmit' => ServiceAvailability::canSubmit('bvn-verification', 'bvn'),
            'price' => ServicePrice::amountFor('bvn-verification', 'bvn'),
            'history' => BvnVerificationRecord::query()->where('user_id', Auth::id())->latest('id')->limit(5)->get(),
        ]);
    }
}
