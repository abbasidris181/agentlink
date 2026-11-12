<?php

namespace App;

use App\Models\AdminAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ChangeUserAccess
{
    /** @param list<string> $permissions */
    public function apply(?User $actor, int $userId, string $role, array $permissions = []): void
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('roles.manage');
        }
        Validator::make(compact('role', 'permissions'), ['role' => ['required', Rule::in(AdminAccess::ROLES)], 'permissions' => ['array'], 'permissions.*' => ['string', Rule::in(array_keys(AdminAccess::permissions()))]])->validate();
        DB::transaction(function () use ($actor, $userId, $role, $permissions): void {
            $administrators = User::query()->where('role', 'super_admin')->orderBy('id')->lockForUpdate()->get();
            if ($actor !== null) {
                Gate::forUser($actor)->authorize('roles.manage');
            }
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            if (! $user->is_active && in_array($role, ['super_admin', 'staff'], true)) {
                throw ValidationException::withMessages(['role' => 'Reactivate this account before granting administrative access.']);
            }
            if ($user->role === 'super_admin' && $user->is_active && $role !== 'super_admin' && $administrators->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'The last active super administrator cannot be removed.']);
            }
            $before = ['role' => $user->role, 'permissions' => $user->staff_permissions ?? []];
            $user->role = $role;
            $user->staff_permissions = $role === 'staff' ? AdminAccess::withDependencies($permissions) : [];
            $user->save();
            AdminAudit::create(['actor_id' => $actor?->id, 'user_id' => $user->id, 'event' => 'user.access_updated', 'details' => ['before' => $before, 'after' => ['role' => $role, 'permissions' => $user->staff_permissions]]]);
        }, 3);
    }
}
