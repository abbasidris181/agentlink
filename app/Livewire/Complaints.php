<?php

namespace App\Livewire;

use App\Models\AdminAudit;
use App\Models\ServiceRequest;
use App\Models\User;
use App\ServiceCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Complaints extends Component
{
    /** @var list<string> */
    public array $types = [];

    public string $complaint = '';

    #[Locked]
    public string $requestKey = '';

    #[Locked]
    public string $submittedReference = '';

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
        $this->requestKey = (string) Str::uuid();
    }

    public function hydrate(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function updatedTypes(): void
    {
        $this->resetSubmission();
    }

    public function updatedComplaint(): void
    {
        $this->resetSubmission();
    }

    private function resetSubmission(): void
    {
        $this->submittedReference = '';
        $this->requestKey = (string) Str::uuid();
        $this->resetValidation();
    }

    public function submit(): void
    {
        abort_unless(Auth::check(), 403);
        $this->submittedReference = '';
        $this->complaint = trim($this->complaint);
        $options = ServiceCatalog::complaintTypes();
        $this->validate([
            'types' => ['required', 'array', 'list', 'min:1', 'max:'.count($options)],
            'types.*' => ['required', 'string', 'distinct', Rule::in(array_keys($options))],
            'complaint' => ['required', 'string', 'max:5000'],
        ], ['types.required' => 'Select at least one service or Other.'], ['complaint' => 'complain', 'types' => 'types']);
        $labels = [];
        foreach ($options as $key => $label) {
            if (in_array($key, $this->types, true)) {
                $labels[] = $label;
            }
        }
        $payload = ['types' => implode(', ', $labels), 'complaint' => $this->complaint];
        $record = DB::transaction(function () use ($payload): ServiceRequest {
            $owner = User::query()->lockForUpdate()->findOrFail(Auth::id());
            abort_unless($owner->is_active && ! $owner->needsEmailVerification(), 403);
            $existing = ServiceRequest::query()->where('request_key', $this->requestKey)->first();
            if ($existing !== null) {
                if ($existing->user_id !== $owner->id || $existing->service !== 'complaints' || $existing->payload !== $payload) {
                    throw ValidationException::withMessages(['complaint' => 'This complaint reference has already been used. Reload the page.']);
                }

                return $existing;
            }
            $record = ServiceRequest::create([
                'reference' => (string) Str::ulid(), 'request_key' => $this->requestKey,
                'user_id' => $owner->id, 'service' => 'complaints', 'method' => 'complaint',
                'payload' => $payload, 'quoted_price' => 0, 'status' => 'pending',
            ]);
            AdminAudit::create([
                'actor_id' => $owner->id, 'user_id' => $owner->id, 'event' => 'complaint.submitted',
                'details' => ['reference' => $record->reference],
            ]);

            return $record;
        }, 3);
        $this->submittedReference = $record->reference;
    }

    public function render(): View
    {
        return view('livewire.complaints', [
            'options' => ServiceCatalog::complaintTypes(),
            'history' => ServiceRequest::query()->where('user_id', Auth::id())->where('service', 'complaints')
                ->latest('id')->limit(20)->get(),
        ]);
    }
}
