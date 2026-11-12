<?php

namespace App\Livewire;

use App\BvnLicenseOnboardingInput;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\NigeriaLocations;
use App\ServiceAvailability;
use App\SubmitServiceRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class BvnLicenseOnboarding extends Component
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
        ServiceAvailability::assertEnabled('bvn-license-onboarding');
        $this->requestKey = (string) Str::uuid();
        $this->details = array_fill_keys(array_diff(array_keys(BvnLicenseOnboardingInput::FIELDS), ['region']), '');
    }

    public function hydrate(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function updated(string $property): void
    {
        $this->requestKey = (string) Str::uuid();
        $this->showAvailability = false;
        if ($property === 'details.state') {
            $this->details['lga'] = '';
            $this->resetValidation(['details.state', 'details.lga', 'details.region']);
        }
    }

    #[Computed]
    public function region(): string
    {
        return NigeriaLocations::region($this->selectedState());
    }

    public function submit(SubmitServiceRequest $submission): void
    {
        abort_unless(Auth::check() && Auth::user()->fresh()->is_active, 403);
        $this->showAvailability = false;
        ServiceAvailability::validateSubmission('bvn-license-onboarding', 'enrollment');
        $input = BvnLicenseOnboardingInput::validate([
            ...$this->details, 'region' => NigeriaLocations::region($this->selectedState()),
        ], 'details');
        $this->details = array_diff_key($input, ['region' => true]);
        $record = $submission->create(Auth::user(), 'bvn-license-onboarding', 'enrollment', $input, $this->requestKey);
        $this->submittedReference = $record->reference;
        $this->showAvailability = true;
    }

    private function selectedState(): string
    {
        return is_string($this->details['state'] ?? null) ? $this->details['state'] : '';
    }

    public function render(): View
    {
        return view('livewire.bvn-license-onboarding', [
            'selectedState' => $this->selectedState(),
            'fields' => BvnLicenseOnboardingInput::FIELDS,
            'states' => NigeriaLocations::states(),
            'lgas' => NigeriaLocations::lgas($this->selectedState()),
            'price' => ServicePrice::amountFor('bvn-license-onboarding', 'enrollment'),
            'canSubmit' => ServiceAvailability::canSubmit('bvn-license-onboarding', 'enrollment'),
            'history' => ServiceRequest::query()->where('user_id', Auth::id())->where('service', 'bvn-license-onboarding')->latest('id')->limit(20)->get(),
        ]);
    }
}
