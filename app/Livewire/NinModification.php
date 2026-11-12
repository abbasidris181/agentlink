<?php

namespace App\Livewire;

use App\Models\ServiceRequest;
use App\ServiceAvailability;
use App\ServiceCatalog;
use App\SubmitServiceRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class NinModification extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $stage = 'first';

    /** @var list<string> */
    public array $types = ['name'];

    public string $nin = '';

    /** @var array<string, string> */
    public array $details = [];

    public ?TemporaryUploadedFile $photo = null;

    #[Locked]
    public bool $showAvailability = false;

    #[Locked]
    public string $requestKey = '';

    #[Locked]
    public string $submittedReference = '';

    public function updated(string $property): void
    {
        $this->requestKey = (string) Str::uuid();
        $this->showAvailability = false;
    }

    public function mount(string $stage = 'first'): void
    {
        abort_unless(Auth::check(), 403);
        ServiceAvailability::assertEnabled('nin-modification');
        if ($this->requestKey === '') {
            $this->requestKey = (string) Str::uuid();
        }
        abort_unless(in_array($stage, ['first', 'second'], true), 404);
        $this->stage = $stage;
    }

    public function hydrate(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function updatedTypes(): void
    {
        $this->details = array_intersect_key($this->details, $this->fields());
        if (! $this->requiresPhoto()) {
            $this->reset('photo');
        }
        $this->showAvailability = false;
        $this->resetValidation();
    }

    /** @return array<string, array{label: string, type: string, required: bool}> */
    #[Computed]
    public function fields(): array
    {
        $names = [
            'first_name' => ['label' => 'First name', 'type' => 'text', 'required' => true],
            'surname' => ['label' => 'Surname', 'type' => 'text', 'required' => true],
            'middle_name' => ['label' => 'Middle name (optional)', 'type' => 'text', 'required' => false],
        ];
        $fields = [];
        if (in_array('name', $this->types, true)) {
            $fields += $names;
            if ($this->stage === 'second') {
                foreach ($names as $key => $field) {
                    $fields['old_'.$key] = [...$field, 'label' => 'Old '.lcfirst($field['label'])];
                }
            }
        }
        if (in_array('phone', $this->types, true)) {
            $fields['phone'] = ['label' => 'New phone number', 'type' => 'tel', 'required' => true];
        }
        if ($this->hasAnyType(['dob', 'dob_above_5', 'dob_above_10', 'dob_above_15'])) {
            $fields['dob'] = ['label' => 'New date of birth', 'type' => 'date', 'required' => true];
            if ($this->stage === 'second') {
                $fields['old_dob'] = ['label' => 'Old date of birth', 'type' => 'date', 'required' => true];
            }
        }
        if (in_array('address', $this->types, true)) {
            $fields['address'] = ['label' => 'Address', 'type' => 'textarea', 'required' => true];
        }
        if (in_array('gender', $this->types, true)) {
            $fields['gender'] = ['label' => 'Correct gender', 'type' => 'select', 'required' => true];
        }

        return $fields;
    }

    public function submit(SubmitServiceRequest $submission): void
    {
        abort_unless(Auth::check() && Auth::user()->fresh()->is_active, 403);
        $this->showAvailability = false;
        ServiceAvailability::validateSubmission('nin-modification');
        $this->nin = trim($this->nin);
        foreach ($this->details as $key => $value) {
            if (is_string($value)) {
                $this->details[$key] = trim($value);
            }
        }
        $attributes = ['nin' => 'NIN', 'photo' => 'clear photo', 'types' => 'modification types'];
        foreach ($this->fields as $key => $field) {
            $attributes['details.'.$key] = strtolower(str_replace(' (optional)', '', $field['label']));
        }
        $this->validate($this->rules(), [], $attributes);
        $input = ['nin' => $this->nin, 'types' => implode(', ', array_keys(array_intersect_key(ServiceCatalog::modificationTypes(), array_flip($this->types))))];
        $input += array_intersect_key($this->details, $this->fields());
        $record = $submission->create(Auth::user(), 'nin-modification', $this->stage, $input, $this->requestKey, $this->requiresPhoto() ? $this->photo : null);
        $this->submittedReference = $record->reference;
        $this->showAvailability = true;
    }

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        $rules = [
            'types' => ['required', 'array', 'list', 'min:1', 'max:8'],
            'types.*' => ['required', 'string', 'distinct', 'in:name,phone,dob,dob_above_5,dob_above_10,dob_above_15,address,gender'],
            'nin' => ['required', 'string', 'regex:/\A[0-9]{11}\z/'],
        ];
        foreach ($this->fields as $key => $field) {
            $rules['details.'.$key] = [
                $field['required'] ? 'required' : 'nullable',
                ...match ($field['type']) {
                    'date' => ['date_format:Y-m-d', 'before_or_equal:today'],
                    'select' => ['in:male,female'],
                    'tel' => ['string', 'regex:/\A(?:0[1-9][0-9]{9}|(?:\+?234)?[1-9][0-9]{9})\z/'],
                    default => ['string', 'max:'.($key === 'address' ? '1000' : '100')],
                },
            ];
        }
        if ($this->requiresPhoto) {
            $rules['photo'] = ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];
        }

        return $rules;
    }

    #[Computed]
    public function requiresPhoto(): bool
    {
        return $this->hasAnyType(['name', 'phone', 'dob', 'dob_above_5', 'dob_above_10', 'dob_above_15', 'address']);
    }

    /** @param list<string> $types */
    private function hasAnyType(array $types): bool
    {
        foreach ($types as $type) {
            if (in_array($type, $this->types, true)) {
                return true;
            }
        }

        return false;
    }

    public function render(): View
    {
        return view('livewire.nin-modification', ['canSubmit' => ServiceAvailability::canSubmit('nin-modification', array_map(fn (string $type): string => $this->stage.'_'.$type, $this->types)),
            'price' => SubmitServiceRequest::priceFor('nin-modification', $this->stage, ['types' => implode(', ', $this->types)]),
            'history' => ServiceRequest::query()->where('user_id', Auth::id())->where('service', 'nin-modification')->where('method', $this->stage)->latest('id')->limit(20)->get(),
        ]);
    }
}
