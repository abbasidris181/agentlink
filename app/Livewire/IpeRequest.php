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

class IpeRequest extends Component
{
    #[Locked]
    public string $service = 'ipe-clearing';

    public string $trackingId = '';

    #[Locked]
    public bool $showAvailability = false;

    #[Locked]
    public string $requestKey = '';

    #[Locked]
    public string $submittedReference = '';

    #[Locked]
    public int $submittedPriceMinor = 0;

    public function mount(string $service = 'ipe-clearing'): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless(in_array($service, ['ipe-clearing', 'mod-ipe'], true), 404);

        $this->service = $service;
        ServiceAvailability::assertEnabled($this->service);
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
        ServiceAvailability::validateSubmission($this->service, 'tracking');

        $this->trackingId = trim($this->trackingId);

        $this->validate([
            'trackingId' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{15}\z/'],
        ], [
            'trackingId.required' => 'Enter your 15-character tracking ID.',
            'trackingId.regex' => 'Enter exactly 15 letters or digits for the tracking ID.',
        ]);

        $record = $submission->create(Auth::user(), $this->service, 'tracking', ['tracking_id' => $this->trackingId], $this->requestKey);
        $this->submittedReference = $record->reference;
        $this->submittedPriceMinor = $record->quoted_price;
        $this->requestKey = (string) Str::uuid();
        $this->showAvailability = true;
    }

    public function render(): View
    {
        return view('livewire.ipe-request', ['canSubmit' => ServiceAvailability::canSubmit($this->service, 'tracking'),
            'history' => ServiceRequest::query()->where('user_id', Auth::id())->where('service', $this->service)->latest('id')->limit(20)->get(),
            'price' => ServicePrice::amountFor($this->service, 'tracking'),
            'serviceName' => $this->service === 'ipe-clearing' ? 'IPE Clearing' : 'MOD-IPE',
        ]);
    }
}
