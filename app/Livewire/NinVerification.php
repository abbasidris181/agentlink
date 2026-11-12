<?php

namespace App\Livewire;

use App\Models\NinVerificationRecord;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\NinVerificationService;
use App\ServiceAvailability;
use App\SubmitServiceRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NinVerification extends Component
{
    #[Locked]
    public string $version = 'v1';

    public string $method = 'nin';

    public string $nin = '';

    public string $phone = '';

    public string $first_name = '';

    public string $surname = '';

    public string $middle_name = '';

    public string $dob = '';

    public string $gender = '';

    public bool $consent = false;

    #[Locked]
    public bool $showAvailability = false;

    #[Locked]
    public string $requestKey = '';

    #[Locked]
    public string $submittedReference = '';

    public function mount(string $version = 'v1'): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless(in_array($version, ['v1', 'v2', 'v3'], true), 404);

        $this->version = $version;
        $this->requestKey = (string) Str::uuid();
        ServiceAvailability::assertEnabled('nin-'.$this->version);
    }

    public function hydrate(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function updated(string $property): void
    {
        if ($property !== 'method') {
            $this->requestKey = (string) Str::uuid();
            $this->showAvailability = false;
        }
    }

    public function updatedMethod(): void
    {
        $this->requestKey = (string) Str::uuid();
        $this->reset('nin', 'phone', 'first_name', 'surname', 'middle_name', 'dob', 'gender', 'consent', 'showAvailability');
        $this->resetValidation();
    }

    public function verify(NinVerificationService $provider, SubmitServiceRequest $submission): void
    {
        abort_unless(Auth::check(), 403);
        $this->showAvailability = false;
        ServiceAvailability::validateSubmission('nin-'.$this->version, $this->method);

        $this->showAvailability = false;
        $this->nin = trim($this->nin);
        $this->phone = preg_replace('/[\s()-]+/', '', trim($this->phone));
        $this->first_name = trim($this->first_name);
        $this->surname = trim($this->surname);
        $this->middle_name = trim($this->middle_name);

        $methodRules = match ($this->method) {
            'nin' => ['nin' => ['required', 'string', 'regex:/^[0-9]{11}$/']],
            'phone' => ['phone' => ['required', 'string', 'regex:/^(?:0[1-9][0-9]{9}|(?:\+?234)?[1-9][0-9]{9})$/']],
            'demographics' => [
                'first_name' => ['required', 'string', 'max:100'],
                'surname' => ['required', 'string', 'max:100'],
                'middle_name' => ['nullable', 'string', 'max:100'],
                'dob' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'gender' => ['required', 'in:male,female'],
            ],
            default => [],
        };

        $this->validate([
            'consent' => ['accepted'],
            'method' => ['required', 'in:nin,phone,demographics'],
            ...$methodRules,
        ], [
            'consent.accepted' => 'Please give your consent before continuing with verification.',
            'nin.regex' => 'Enter an 11-digit NIN number.',
            'phone.regex' => 'Enter a valid phone number, with or without the +234 prefix.',
        ], [
            'nin' => 'NIN',
            'dob' => 'date of birth',
        ]);

        if (in_array($this->method, ['nin', 'phone'], true)) {
            $record = $provider->verify(Auth::user(), $this->method === 'phone' ? $this->phone : $this->nin, $this->requestKey, $this->method, $this->version);
            if ($record->status === 'successful') {
                $this->redirectRoute('nin.'.$this->version.'.result', ['verification' => $record->reference]);
            } elseif ($record->status === 'charged_error') {
                $this->requestKey = (string) Str::uuid();
                $this->addError('verification', 'The provider returned a verification error. The verification fee has been charged according to this service’s pricing policy.');
            } elseif ($record->status === 'failed') {
                $this->requestKey = (string) Str::uuid();
                $this->addError('verification', 'Verification could not be completed. Any verification charge has been returned to your wallet. Please check the details and try again.');
            } else {
                $this->addError('verification', 'This verification is still processing. Check your history before trying again.');
            }

            return;
        }

        $record = $submission->create(Auth::user(), 'nin-'.$this->version, 'demographics', [
            'first_name' => $this->first_name, 'surname' => $this->surname, 'middle_name' => $this->middle_name,
            'dob' => $this->dob, 'gender' => $this->gender,
        ], $this->requestKey);
        $this->submittedReference = $record->reference;
        $this->showAvailability = true;
    }

    public function render(): View
    {
        return view('livewire.nin-verification', ['canSubmit' => ServiceAvailability::canSubmit('nin-'.$this->version, $this->method),
            'manualHistory' => ServiceRequest::query()->where('user_id', Auth::id())->where('service', 'nin-'.$this->version)->latest('id')->limit(20)->get(),
            'price' => ServicePrice::amountFor('nin-'.$this->version, $this->method),
            'chargeErrors' => (bool) ServicePrice::query()->where('service', 'nin-'.$this->version)->where('method', $this->method)->value('charge_errors'),
            'history' => NinVerificationRecord::query()->where('user_id', Auth::id())->where('version', $this->version)->latest('id')->limit(5)->get(),
        ]);
    }
}
