<?php

namespace App\Livewire;

use App\Models\ServiceRequest;
use App\ServiceAvailability;
use App\ServiceCatalog;
use App\SubmitServiceRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class BvnModification extends Component
{
    public string $kind = '';

    /** @var list<string> */
    public array $types = [];

    public string $bvn = '';

    public string $nin = '';

    public string $surname = '';

    public string $firstName = '';

    public string $middleName = '';

    public string $dob = '';

    public string $phone = '';

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

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
        ServiceAvailability::assertEnabled('bvn-modification');
        if ($this->requestKey === '') {
            $this->requestKey = (string) Str::uuid();
        }
    }

    public function hydrate(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function updatedTypes(): void
    {
        if (! in_array('name', $this->types, true)) {
            $this->reset('surname', 'firstName', 'middleName');
        }
        if (! in_array('dob', $this->types, true)) {
            $this->reset('dob');
        }
        if (! in_array('phone', $this->types, true)) {
            $this->reset('phone');
        }
        $this->showAvailability = false;
        $this->resetValidation();
    }

    public function updatedKind(): void
    {
        $this->showAvailability = false;
        $this->resetValidation();
    }

    /** @return array<string, array{label: string, type: string}> */
    #[Computed]
    public function fields(): array
    {
        $fields = [
            'bvn' => ['label' => 'BVN Number', 'type' => 'text'],
            'nin' => ['label' => 'NIN Number', 'type' => 'text'],
        ];
        if (in_array('name', $this->types, true)) {
            $fields += [
                'surname' => ['label' => 'Surname', 'type' => 'text'],
                'firstName' => ['label' => 'First name', 'type' => 'text'],
                'middleName' => ['label' => 'Middle name', 'type' => 'text'],
            ];
        }
        if (in_array('dob', $this->types, true)) {
            $fields['dob'] = ['label' => 'Date of Birth', 'type' => 'date'];
        }
        if (in_array('phone', $this->types, true)) {
            $fields['phone'] = ['label' => 'New Phone Number', 'type' => 'tel'];
        }

        return $fields;
    }

    public function submit(SubmitServiceRequest $submission): void
    {
        abort_unless(Auth::check() && Auth::user()->fresh()->is_active, 403);
        $this->showAvailability = false;
        ServiceAvailability::validateSubmission('bvn-modification');
        foreach (['bvn', 'nin', 'surname', 'firstName', 'middleName', 'dob', 'phone'] as $field) {
            $this->{$field} = trim($this->{$field});
        }
        $this->phone = preg_replace('/[\s()-]+/', '', $this->phone);
        $rules = [
            'kind' => ['required', Rule::in(array_keys(ServiceCatalog::bvnKinds()))],
            'types' => ['required', 'array', 'list', 'min:1', 'max:3'],
            'types.*' => ['required', 'string', 'distinct', 'in:name,dob,phone'],
            'bvn' => ['required', 'string', 'regex:/\A[0-9]{11}\z/'],
            'nin' => ['required', 'string', 'regex:/\A[0-9]{11}\z/'],
        ];
        if (in_array('name', $this->types, true)) {
            foreach (['surname', 'firstName', 'middleName'] as $field) {
                $rules[$field] = ['required', 'string', 'max:100'];
            }
        }
        if (in_array('dob', $this->types, true)) {
            $rules['dob'] = ['required', 'date_format:Y-m-d', 'before_or_equal:today'];
        }
        if (in_array('phone', $this->types, true)) {
            $rules['phone'] = ['required', 'string', 'regex:/\A(?:0[1-9][0-9]{9}|(?:\+?234)?[1-9][0-9]{9})\z/'];
        }
        $this->validate($rules, [
            'types.required' => 'Select at least one modification type.',
            'bvn.regex' => 'Enter an 11-digit BVN number.',
            'nin.regex' => 'Enter an 11-digit NIN number.',
        ], [
            'bvn' => 'BVN number', 'nin' => 'NIN number', 'firstName' => 'first name',
            'middleName' => 'middle name', 'dob' => 'date of birth', 'phone' => 'new phone number',
            'kind' => 'kind', 'types' => 'modification types',
        ]);
        $input = ['bvn' => $this->bvn, 'nin' => $this->nin, 'types' => implode(', ', array_keys(array_intersect_key(['name' => true, 'dob' => true, 'phone' => true], array_flip($this->types))))];
        foreach ($this->fields() as $field => $definition) {
            $key = match ($field) {
                'firstName' => 'first_name',
                'middleName' => 'middle_name',
                default => $field,
            };
            $input[$key] = $this->{$field};
        }
        $record = $submission->create(Auth::user(), 'bvn-modification', $this->kind, $input, $this->requestKey);
        $this->submittedReference = $record->reference;
        $this->showAvailability = true;
    }

    public function render(): View
    {
        return view('livewire.bvn-modification', ['canSubmit' => ServiceAvailability::canSubmit('bvn-modification', array_map(fn (string $type): string => $this->kind.'_'.$type, $this->types)),
            'kinds' => ServiceCatalog::bvnKinds(),
            'price' => SubmitServiceRequest::priceFor('bvn-modification', $this->kind, ['types' => implode(', ', $this->types)]),
            'history' => ServiceRequest::query()->where('user_id', Auth::id())->where('service', 'bvn-modification')->latest('id')->limit(20)->get(),
        ]);
    }
}
