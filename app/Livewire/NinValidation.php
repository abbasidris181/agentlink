<?php

namespace App\Livewire;

use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\ServiceAvailability;
use App\SubmitServiceRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NinValidation extends Component
{
    public string $nin = '';

    #[Locked]
    public bool $showAvailability = false;

    #[Locked]
    public string $requestKey = '';

    #[Locked]
    public string $submittedReference = '';

    #[Locked]
    public int $submittedPriceMinor = 0;

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
        ServiceAvailability::assertEnabled('validations');
        $this->requestKey = (string) Str::uuid();
    }

    public function hydrate(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function submit(SubmitServiceRequest $submission): void
    {
        abort_unless(Auth::check(), 403);
        $this->showAvailability = false;
        ServiceAvailability::validateSubmission('validations', 'nin');

        $this->nin = trim($this->nin);

        $this->validate([
            'nin' => ['required', 'string', 'regex:/\A[0-9]{11}\z/'],
        ], [
            'nin.required' => 'Enter your 11-digit NIN number.',
            'nin.regex' => 'Enter an 11-digit NIN number.',
        ], [
            'nin' => 'NIN',
        ]);

        $record = $submission->create(Auth::user(), 'validations', 'nin', ['nin' => $this->nin], $this->requestKey);
        $this->submittedReference = $record->reference;
        $this->submittedPriceMinor = $record->quoted_price;
        $this->requestKey = (string) Str::uuid();
        $this->showAvailability = true;
    }

    public function render(): View
    {
        return view('livewire.nin-validation', ['canSubmit' => ServiceAvailability::canSubmit('validations', 'nin'), 'history' => ServiceRequest::query()->where('user_id', Auth::id())->where('service', 'validations')->latest('id')->limit(20)->get(), 'price' => ServicePrice::amountFor('validations', 'nin')]);
    }
}
