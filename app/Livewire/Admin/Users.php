<?php

namespace App\Livewire\Admin;

use App\ChangeUserAccess;
use App\Models\AdminAudit;
use App\Models\User;
use App\WalletAdjustment;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class Users extends AdminComponent
{
    use WithPagination;

    #[Locked]
    public ?int $userId = null;

    #[Locked]
    public string $requestKey = '';

    public string $role = 'users';

    /** @var list<string> */
    public array $staffPermissions = [];

    public string $search = '';

    public string $statusFilter = 'all';

    public string $firstName = '';

    public string $surname = '';

    public string $email = '';

    public string $phone = '';

    public string $business = '';

    public string $address = '';

    public string $direction = 'credit';

    public string $amount = '';

    public string $reason = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public function mount(?int $userId = null): void
    {
        $this->userId = $userId;
        $this->requestKey = (string) Str::uuid();

        if ($userId !== null) {
            $user = $this->targetUser();
            if (Gate::allows('roles.manage')) {
                $this->role = $user->role;
                $this->staffPermissions = $user->staff_permissions ?? [];
            }
            if (! Gate::allows('users.edit')) {
                return;
            }
            $this->firstName = $user->first_name ?? '';
            $this->surname = $user->surname ?? '';
            $this->email = $user->email;
            $this->phone = $user->phone ?? '';
            $this->business = $user->business ?? '';
            $this->address = $user->address ?? '';
        }
    }

    public function hydrate(): void
    {
        if (! Gate::allows('users.edit')) {
            $this->reset('firstName', 'surname', 'email', 'phone', 'business', 'address');
        }
        if (! Gate::allows('roles.manage')) {
            $this->reset('role', 'staffPermissions');
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function targetUser(): User
    {
        abort_if($this->userId === null, 404);

        return User::query()->findOrFail($this->userId);
    }

    public function saveProfile(): void
    {
        Gate::authorize('users.edit');
        $user = $this->targetUser();
        $this->authorizeCustomerTarget($user);
        $this->firstName = trim($this->firstName);
        $this->surname = trim($this->surname);
        $this->email = mb_strtolower(trim($this->email));
        $this->validate([
            'firstName' => ['required', 'string', 'max:100'],
            'surname' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'business' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($user): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->authorizeCustomerTarget($user);
            $user->fill([
                'first_name' => $this->firstName, 'surname' => $this->surname,
                'name' => $this->firstName.' '.$this->surname, 'email' => $this->email,
                'phone' => trim($this->phone), 'business' => trim($this->business), 'address' => trim($this->address),
            ]);

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            $fields = array_keys($user->getDirty());
            $user->save();
            AdminAudit::create(['actor_id' => Auth::id(), 'user_id' => $user->id, 'event' => 'user.updated', 'details' => ['fields' => $fields]]);
        });

        session()->flash('status', 'User profile updated.');
    }

    public function adjustWallet(WalletAdjustment $adjustment): void
    {
        Gate::authorize('wallet.adjust');
        $user = $this->targetUser();
        $this->amount = trim($this->amount);
        $transaction = $adjustment->apply(Auth::user(), $user->id, $this->direction, $this->amount, $this->reason, $this->requestKey);
        $this->reset('amount', 'reason');
        $this->requestKey = (string) Str::uuid();
        session()->flash('status', 'Wallet adjustment recorded: '.$transaction->reference);
    }

    public function toggleStatus(): void
    {
        Gate::authorize('users.status');

        DB::transaction(function (): void {
            $user = User::query()->lockForUpdate()->findOrFail($this->userId);

            $this->authorizeCustomerTarget($user);
            if ($user->role === 'super_admin') {
                throw ValidationException::withMessages(['accountStatus' => 'Administrator accounts cannot be suspended here.']);
            }

            $user->is_active = ! $user->is_active;
            $user->remember_token = Str::random(60);
            $user->save();
            $this->revokeDatabaseSessions($user);
            AdminAudit::create(['actor_id' => Auth::id(), 'user_id' => $user->id, 'event' => $user->is_active ? 'user.activated' : 'user.suspended']);
        });

        session()->flash('status', 'Account status updated.');
    }

    public function resetPassword(): void
    {
        Gate::authorize('users.password');
        $user = $this->targetUser();
        $this->authorizeCustomerTarget($user);
        $this->validate([
            'newPassword' => ['required', 'string', 'min:8', 'max:72', 'confirmed', function (string $attribute, mixed $value, Closure $fail): void {
                if (strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            }],
        ]);

        DB::transaction(function () use ($user): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->authorizeCustomerTarget($user);
            $user->password = $this->newPassword;
            $user->remember_token = Str::random(60);
            $user->save();
            $this->revokeDatabaseSessions($user);
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            AdminAudit::create(['actor_id' => Auth::id(), 'user_id' => $user->id, 'event' => 'user.password_reset']);
        });

        $this->reset('newPassword', 'newPassword_confirmation');
        session()->flash('status', 'Password reset. Existing sessions and remember-me access have been revoked.');

        if ($user->id === Auth::id()) {
            $this->logout();
        }
    }

    protected function revokeDatabaseSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    protected function authorizeCustomerTarget(User $user): void
    {
        abort_unless(Gate::allows('roles.manage') || in_array($user->role, ['users', 'agent'], true), 403);
    }

    public function saveAccess(ChangeUserAccess $access): void
    {
        Gate::authorize('roles.manage');
        $access->apply(Auth::user(), $this->targetUser()->id, $this->role, $this->staffPermissions);
        $this->staffPermissions = $this->targetUser()->staff_permissions ?? [];
        session()->flash('status', 'Role and staff permissions updated. Required view permissions are included automatically.');
    }

    public function render(): View
    {
        $columns = ['id', 'name', 'role', 'is_active', 'created_at'];
        if (Gate::allows('users.contact')) {
            $columns = array_merge($columns, ['email', 'phone', 'first_name', 'surname', 'business', 'address', 'email_verified_at', 'last_login_at']);
        }
        if (Gate::allows('users.balances')) {
            $columns[] = 'wallet_balance';
        }
        $query = User::query()->select($columns);
        if (Gate::allows('users.transactions')) {
            $query->withCount('walletTransactions');
        }
        if (trim($this->search) !== '') {
            $search = '%'.trim($this->search).'%';
            $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', $search);
                if (Gate::allows('users.contact')) {
                    $query->orWhere('email', 'like', $search)->orWhere('phone', 'like', $search);
                }
            });
        }
        if (in_array($this->statusFilter, ['active', 'suspended'], true)) {
            $query->where('is_active', $this->statusFilter === 'active');
        }
        $user = $this->userId === null ? null : User::query()->select($columns)->findOrFail($this->userId);

        return view('livewire.admin.users', [
            'users' => $user === null ? $query->latest('id')->paginate(15) : null,
            'selectedUser' => $user,
            'latestTransactions' => $user !== null && Gate::allows('users.transactions') ? $user->walletTransactions()->with('actor:id,name')->latest('id')->limit(20)->get() : collect(),
            'userAudits' => $user !== null && Gate::allows('users.activity') ? AdminAudit::query()->where('user_id', $user->id)->with('actor:id,name')->latest('id')->limit(10)->get() : collect(),
        ]);
    }
}
