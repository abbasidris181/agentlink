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
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class BvnRetrieval extends Component
{
    use WithFileUploads;

    public string $method = 'phone';

    public string $phone = '';

    public string $fullName = '';

    public string $agentCode = '';

    public string $ticketId = '';

    public string $bmsTicket = '';

    public ?TemporaryUploadedFile $screenshotProof = null;

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
        ServiceAvailability::assertEnabled('bvn-retrieval');
        $this->requestKey = (string) Str::uuid();
    }

    public function hydrate(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function updatedMethod(): void
    {
        $this->reset('phone', 'fullName', 'agentCode', 'ticketId', 'bmsTicket', 'screenshotProof', 'showAvailability');
        $this->resetValidation();
    }

    public function submit(SubmitServiceRequest $submission): void
    {
        abort_unless(Auth::check(), 403);
        $this->showAvailability = false;
        ServiceAvailability::validateSubmission('bvn-retrieval', $this->method);

        $this->phone = preg_replace('/[\s()-]+/', '', trim($this->phone));
        $this->fullName = trim($this->fullName);
        $this->agentCode = trim($this->agentCode);
        $this->ticketId = trim($this->ticketId);
        $this->bmsTicket = trim($this->bmsTicket);

        $methodRules = match ($this->method) {
            'phone' => [
                'phone' => ['required', 'string', 'regex:/\A(?:0[1-9][0-9]{9}|(?:\+?234)?[1-9][0-9]{9})\z/'],
                'fullName' => ['required', 'string', 'max:200'],
            ],
            'crm' => [
                'agentCode' => ['required', 'string', 'max:100'],
                'ticketId' => ['required', 'string', 'max:100'],
                'bmsTicket' => ['required', 'string', 'max:100'],
                'screenshotProof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ],
            default => [],
        };

        $this->validate([
            'method' => ['required', 'in:phone,crm'],
            ...$methodRules,
        ], [
            'phone.regex' => 'Enter a valid registered phone number, with or without the +234 prefix.',
            'screenshotProof.required' => 'Upload screenshot proof for your CRM request.',
            'screenshotProof.image' => 'Upload a valid screenshot image.',
            'screenshotProof.mimes' => 'Upload a JPG, PNG, or WebP screenshot.',
            'screenshotProof.max' => 'The screenshot must be 5 MB or smaller.',
        ], [
            'phone' => 'registered phone number',
            'fullName' => 'full name',
            'agentCode' => 'agent code',
            'ticketId' => 'ticket ID',
            'bmsTicket' => 'BMS ticket',
            'screenshotProof' => 'screenshot proof',
        ]);

        $record = $submission->create(Auth::user(), 'bvn-retrieval', $this->method, $this->method === 'phone' ? ['phone' => $this->phone, 'full_name' => $this->fullName] : ['agent_code' => $this->agentCode, 'ticket_id' => $this->ticketId, 'bms_ticket' => $this->bmsTicket], $this->requestKey, $this->method === 'crm' ? $this->screenshotProof : null);
        $this->submittedReference = $record->reference;
        $this->submittedPriceMinor = $record->quoted_price;
        $this->requestKey = (string) Str::uuid();
        $this->showAvailability = true;
    }

    public function render(): View
    {
        return view('livewire.bvn-retrieval', ['canSubmit' => ServiceAvailability::canSubmit('bvn-retrieval', $this->method), 'history' => ServiceRequest::query()->where('user_id', Auth::id())->where('service', 'bvn-retrieval')->latest('id')->limit(20)->get(), 'price' => ServicePrice::amountFor('bvn-retrieval', $this->method)]);
    }
}
