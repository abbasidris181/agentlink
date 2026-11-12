<?php

namespace App\Livewire;

use App\Models\ServiceRequest;
use App\ServiceAvailability;
use App\SubmitServiceRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Delinking extends Component
{
    public string $nin = '';

    /** @var list<string> */
    public array $types = ['delinking'];

    public string $email = '';

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
        ServiceAvailability::assertEnabled('delinking');
        $this->requestKey = (string) Str::uuid();
    }

    public function hydrate(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function updatedTypes(): void
    {
        if (! in_array('delinking', $this->types, true)) {
            $this->email = '';
        }
        $this->showAvailability = false;
        $this->requestKey = (string) Str::uuid();
        $this->resetValidation();
    }

    public function submit(SubmitServiceRequest $submission): void
    {
        abort_unless(Auth::check(), 403);
        $this->showAvailability = false;
        ServiceAvailability::validateSubmission('delinking');

        $this->nin = trim($this->nin);
        $this->email = trim($this->email);

        $this->validate([
            'nin' => ['required', 'string', 'regex:/\A[0-9]{11}\z/'],
            'types' => ['required', 'array', 'list', 'min:1', 'max:2'],
            'types.*' => ['required', 'string', 'distinct', 'in:delinking,email_retrieve'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
        ], [
            'nin.required' => 'Enter your 11-digit NIN number.',
            'nin.regex' => 'Enter an 11-digit NIN number.',
        ], [
            'nin' => 'NIN',
        ]);

        $selectedTypes = [];
        foreach (['delinking' => 'Delinking', 'email_retrieve' => 'Email retrive'] as $key => $label) {
            if (in_array($key, $this->types, true)) {
                $selectedTypes[] = $label;
            }
        }
        $input = ['nin' => $this->nin, 'types' => implode(', ', $selectedTypes)];
        if (in_array('delinking', $this->types, true) && $this->email !== '') {
            $input['email'] = $this->email;
        }
        $record = $submission->create(Auth::user(), 'delinking', 'nin', $input, $this->requestKey);
        $this->submittedReference = $record->reference;
        $this->submittedPriceMinor = $record->quoted_price;
        $this->requestKey = (string) Str::uuid();
        $this->showAvailability = true;
    }

    public function render(): View
    {
        return view('livewire.delinking', ['canSubmit' => ServiceAvailability::canSubmit('delinking', array_values(array_intersect_key(['delinking' => 'nin', 'email_retrieve' => 'email_retrieve'], array_flip($this->types)))), 'history' => ServiceRequest::query()->where('user_id', Auth::id())->where('service', 'delinking')->latest('id')->limit(20)->get(), 'price' => SubmitServiceRequest::priceFor('delinking', 'nin', ['types' => implode(', ', array_values(array_intersect_key(['delinking' => 'Delinking', 'email_retrieve' => 'Email retrive'], array_flip($this->types))))])]);
    }
}
