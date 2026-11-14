<?php

namespace App\Livewire;

use App\DemographicsSearchInput;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\ServiceAvailability;
use App\SubmitServiceRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DemographicsSearch extends Component
{
    /** @var array<string, string> */
    public array $details = [];

    #[Locked]
    public string $requestKey = '';

    #[Locked]
    public string $submittedReference = '';

    #[Locked]
    public bool $showAvailability = false;

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
        ServiceAvailability::assertEnabled('demographics-search');
        $this->requestKey = (string) Str::uuid();
        $this->details = array_fill_keys(array_keys(DemographicsSearchInput::FIELDS), '');
    }

    public function hydrate(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function updated(string $property): void
    {
        $this->requestKey = (string) Str::uuid();
        $this->showAvailability = false;
        $this->resetValidation();
    }

    public function submit(SubmitServiceRequest $submission): void
    {
        abort_unless(Auth::check() && Auth::user()->fresh()->is_active, 403);
        $this->showAvailability = false;
        ServiceAvailability::validateSubmission('demographics-search', 'search');
        $input = DemographicsSearchInput::validate($this->details, 'details');
        $this->details = $input;
        $record = $submission->create(Auth::user(), 'demographics-search', 'search', $input, $this->requestKey);
        $this->submittedReference = $record->reference;
        $this->showAvailability = true;
    }

    public function render(): View
    {
        return view('livewire.demographics-search', [
            'price' => ServicePrice::amountFor('demographics-search', 'search'),
            'canSubmit' => ServiceAvailability::canSubmit('demographics-search', 'search'),
            'history' => ServiceRequest::query()->where('user_id', Auth::id())->where('service', 'demographics-search')->latest('id')->limit(20)->get(),
        ]);
    }
}
