<?php

namespace App\Livewire\Admin;

use App\InstantIpeService;
use App\Models\InstantIpeRequest;
use App\Models\WalletTransaction;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class Transactions extends AdminComponent
{
    use WithPagination;

    public const GROUPS = ['all' => 'All transactions', 'instant-ipe' => 'Instant IPE transactions', 'nin' => 'NIN transactions', 'bvn' => 'BVN transactions'];

    #[Locked]
    public string $transactionGroup = 'all';

    #[Locked]
    public ?int $replyRequestId = null;

    public string $reply = '';

    public function mount(string $transactionGroup = 'all'): void
    {
        abort_unless(array_key_exists($transactionGroup, self::GROUPS), 404);
        $this->transactionGroup = $transactionGroup;
    }

    public function editReply(int $requestId): void
    {
        Gate::authorize('requests.manage');
        $record = $this->actionRequest($requestId);
        $this->replyRequestId = $record->id;
        $this->reply = $record->reply ?? '';
        $this->resetValidation();
    }

    public function cancelReply(): void
    {
        $this->reset('replyRequestId', 'reply');
        $this->resetValidation();
    }

    public function saveReply(InstantIpeService $service): void
    {
        Gate::authorize('requests.manage');
        abort_if($this->replyRequestId === null, 404);
        $record = $this->actionRequest($this->replyRequestId);
        $service->replyToRequest(Auth::user(), $record->id, $this->reply);
        $this->cancelReply();
        session()->flash('status', 'Reply saved. The user can see it in their Instant IPE history and batch report.');
    }

    public function refund(int $requestId, InstantIpeService $service): void
    {
        Gate::authorize('requests.refund');
        $record = $this->actionRequest($requestId);
        $service->refundRequest(Auth::user(), $record->id);
        $this->resetValidation();
        session()->flash('status', 'The original charge has been refunded to the user wallet.');
    }

    public function complete(int $requestId, InstantIpeService $service): void
    {
        Gate::authorize('requests.manage');
        $record = $this->actionRequest($requestId);
        $service->completeRequest(Auth::user(), $record->id);
        $this->resetValidation();
        session()->flash('status', 'Request completed. Automatic status checks have stopped.');
    }

    private function actionRequest(int $requestId): InstantIpeRequest
    {
        abort_unless($this->transactionGroup === 'instant-ipe', 404);

        return InstantIpeRequest::query()->whereHas('walletTransaction', fn (Builder $query): Builder => $query->where('type', 'service_debit')->where('status', 'completed'))->findOrFail($requestId);
    }

    public function render(): View
    {
        $transactions = WalletTransaction::query()->with(Gate::allows('users.contact') ? 'user:id,name,email' : 'user:id,name');
        if ($this->transactionGroup === 'instant-ipe') {
            $transactions->with(['instantIpeRequest.submission:id,batch_id', 'refund']);
        }
        $prefix = match ($this->transactionGroup) {
            'instant-ipe' => 'Instant IPE', 'nin' => 'NIN', 'bvn' => 'BVN', default => null,
        };
        if ($prefix !== null) {
            $transactions->where('service', 'like', $prefix.'%');
        }

        return view('livewire.admin.transactions', ['title' => self::GROUPS[$this->transactionGroup], 'transactions' => $transactions->latest('id')->paginate(20)]);
    }
}
