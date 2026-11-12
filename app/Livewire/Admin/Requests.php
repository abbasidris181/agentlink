<?php

namespace App\Livewire\Admin;

use App\Models\AdminAudit;
use App\Models\ServiceRequest;
use App\RefundServiceRequest;
use App\ServiceCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class Requests extends AdminComponent
{
    use WithPagination;

    #[Locked]
    public ?int $requestId = null;

    #[Locked]
    public int $revision = 0;

    #[Locked]
    public ?string $service = null;

    public string $serviceFilter = 'all';

    public string $statusFilter = 'all';

    public string $search = '';

    public string $status = 'pending';

    public string $reply = '';

    /** @var array<int, string> */
    public array $replies = [];

    public function mount(?int $requestId = null, ?string $service = null): void
    {
        abort_if($service !== null && ! in_array($service, ServiceRequest::ADMIN_SERVICES, true), 404);
        $this->service = $service;
        $this->requestId = $requestId;
        if ($requestId !== null) {
            $record = ServiceRequest::query()->findOrFail($requestId);
            $this->revision = $record->revision;
            if (Gate::allows('requests.manage')) {
                $this->status = $record->status;
                $this->reply = $record->reply ?? '';
            }
        }
    }

    public function hydrate(): void
    {
        if (! Gate::allows('requests.manage')) {
            $this->reset('reply', 'replies');
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedServiceFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function save(): void
    {
        Gate::authorize('requests.manage');
        abort_if($this->requestId === null, 404);
        $this->reply = trim($this->reply);
        $this->validate(['status' => ['required', Rule::in(array_keys(ServiceRequest::STATUSES))], 'reply' => [Rule::requiredIf(in_array($this->status, ['completed', 'rejected'], true)), 'nullable', 'string', 'max:5000']]);
        $newRevision = DB::transaction(function (): int {
            $record = ServiceRequest::query()->lockForUpdate()->findOrFail($this->requestId);
            Gate::authorize('requests.manage');
            if ($record->revision !== $this->revision) {
                throw ValidationException::withMessages(['status' => 'Another administrator updated this request. Reload the page before saving.']);
            }
            if (($record->status === 'refunded') !== ($this->status === 'refunded')) {
                throw ValidationException::withMessages(['status' => 'Use the refund button to issue a refund. Refunded requests must retain their refunded status.']);
            }
            $previous = $record->status;
            $record->status = $this->status;
            $record->reply = $this->reply === '' ? null : $this->reply;
            $record->handled_by = Auth::id();
            $record->handled_at = now();
            $record->revision++;
            $record->save();
            AdminAudit::create(['actor_id' => Auth::id(), 'user_id' => $record->user_id, 'event' => 'service_request.updated', 'details' => ['reference' => $record->reference, 'old_status' => $previous, 'new_status' => $record->status]]);

            return $record->revision;
        }, 3);
        $this->revision = $newRevision;
        session()->flash('status', 'Request updated. The user can see the status and reply in their service history.');
    }

    public function saveReply(int $requestId, int $revision): void
    {
        Gate::authorize('requests.manage');
        $reply = $this->replies[$requestId] ?? '';
        $this->replies[$requestId] = is_string($reply) ? trim($reply) : $reply;
        $this->validate(['replies.'.$requestId => ['required', 'string', 'max:5000']]);
        DB::transaction(function () use ($requestId, $revision): void {
            $record = ServiceRequest::query()->lockForUpdate()->findOrFail($requestId);
            Gate::authorize('requests.manage');
            if ($record->revision !== $revision) {
                throw ValidationException::withMessages(['replies.'.$requestId => 'This request changed. Review the latest reply before saving again.']);
            }
            $record->reply = $this->replies[$requestId];
            $record->handled_by = Auth::id();
            $record->handled_at = now();
            $record->revision++;
            $record->save();
            AdminAudit::create(['actor_id' => Auth::id(), 'user_id' => $record->user_id, 'event' => 'service_request.replied', 'details' => ['reference' => $record->reference]]);
        }, 3);
        unset($this->replies[$requestId]);
        session()->flash('status', 'Reply saved. The user can see it in their service history.');
    }

    public function refund(int $requestId, RefundServiceRequest $refund): void
    {
        $refund->refund(Auth::user(), $requestId);
        $this->resetValidation();
        if ($this->requestId === $requestId) {
            $record = ServiceRequest::query()->findOrFail($requestId);
            $this->status = $record->status;
            $this->revision = $record->revision;
            $this->reply = Gate::allows('requests.manage') ? ($record->reply ?? '') : '';
        }
        session()->flash('status', 'The original payment has been refunded to the user wallet.');
    }

    public function render(): View
    {
        $query = ServiceRequest::query()->whereIn('service', ServiceRequest::ADMIN_SERVICES)->with(['user:id,name,email', 'walletTransaction.refund']);
        $tableService = $this->service ?? (in_array($this->serviceFilter, ServiceRequest::ADMIN_SERVICES, true) ? $this->serviceFilter : null);
        if ($tableService !== null) {
            $query->where('service', $tableService);
        }
        if (array_key_exists($this->statusFilter, ServiceRequest::STATUSES)) {
            $query->where('status', $this->statusFilter);
        }
        if (trim($this->search) !== '') {
            $search = '%'.trim($this->search).'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->where('reference', 'like', $search)->orWhereHas('user', fn (Builder $user): Builder => $user->where('name', 'like', $search)->orWhere('email', 'like', $search));
            });
        }

        $requests = $this->requestId === null ? $query->latest('id')->paginate(20) : null;
        $inputColumns = ServiceRequest::inputColumnsFor($tableService);

        return view('livewire.admin.requests', [
            'requests' => $requests,
            'selectedRequest' => $this->requestId === null ? null : ServiceRequest::query()->with(['user:id,name,email', 'handler:id,name', 'walletTransaction.refund'])->findOrFail($this->requestId),
            'services' => ServiceCatalog::requestServices(),
            'statuses' => ServiceRequest::STATUSES,
            'tableService' => $tableService,
            'tableTitle' => $tableService === null ? 'Service requests' : ServiceCatalog::requestServices()[$tableService]['label'].' requests',
            'inputColumns' => $inputColumns,
        ]);
    }
}
