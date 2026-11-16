<x-admin-shell title="User management" section="users">
    @if ($selectedUser === null)
        <div class="page-banner mb-7"><h1 class="text-3xl font-bold tracking-tight">User management</h1><p class="mt-2 text-sm text-slate-500 dark:text-muted-light">Manage profiles, wallet balances, account access, and recent transactions.</p></div>
        <div class="mb-5 grid gap-3 sm:grid-cols-[1fr_12rem]">
            <div><label for="admin-user-search" class="sr-only">Search users</label><input wire:model.live.debounce.300ms="search" id="admin-user-search" type="search" placeholder="{{ auth()->user()->can('users.contact') ? 'Search name, email, or phone' : 'Search name' }}" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></div>
            <div><label for="admin-user-status" class="sr-only">Account status</label><select wire:model.live="statusFilter" id="admin-user-status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"><option value="all">All accounts</option><option value="active">Active</option><option value="suspended">Suspended</option></select></div>
        </div>
        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-surface-dark">
            <div class="overflow-x-auto"><table class="w-full min-w-4xl text-left text-sm"><caption class="sr-only">User accounts</caption>
                <thead class="bg-slate-50 text-sm text-slate-500 dark:bg-slate-800/60 dark:text-muted-light"><tr><th scope="col" class="px-6 py-4">User</th>@can('users.balances')<th scope="col" class="px-6 py-4">Balance</th>@endcan<th scope="col" class="px-6 py-4">Status</th>@can('users.transactions')<th scope="col" class="px-6 py-4">Transactions</th>@endcan<th scope="col" class="px-6 py-4">Joined</th><th scope="col" class="px-6 py-4">Action</th></tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($users as $user)
                        <tr wire:key="user-{{ $user->id }}"><td class="px-6 py-4"><p class="font-semibold">{{ $user->name }}</p>@can('users.contact')<p class="mt-1 text-xs text-slate-400">{{ $user->email }}</p>@endcan
@if (in_array($user->role, ['super_admin', 'staff'], true))<span class="mt-1 inline-block text-xs font-bold uppercase text-indigo-500">{{ $user->role }}</span>@endif</td>@can('users.balances')<td class="px-6 py-4 font-semibold tabular-nums">{{ \App\Money::format($user->wallet_balance) }}</td>@endcan<td class="px-6 py-4">{{ $user->is_active ? 'Active' : 'Suspended' }}</td>@can('users.transactions')<td class="px-6 py-4">{{ $user->wallet_transactions_count }}</td>@endcan<td class="whitespace-nowrap px-6 py-4 text-xs text-slate-400">{{ $user->created_at->format('d M Y') }}</td><td class="px-6 py-4"><a href="{{ route('admin.users.show', ['userId' => $user->id]) }}" class="font-semibold text-indigo-600 dark:text-indigo-300">Manage</a></td></tr>
                    @empty <tr><td colspan="6" class="px-6 py-12 text-center text-slate-400">No users match your search.</td></tr> @endforelse
                </tbody>
            </table></div>
        </div>
        <div class="mt-5">{{ $users->links() }}</div>
    @else
        <a href="{{ route('admin.users') }}" class="mb-5 inline-flex text-sm font-semibold text-indigo-600 dark:text-indigo-300">&larr; All users</a>
        <div class="mb-6 flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
            <div><p class="text-xs font-semibold uppercase tracking-widest text-indigo-500">User #{{ $selectedUser->id }}</p><h1 class="mt-2 break-words text-3xl font-bold">{{ $selectedUser->name }}</h1><p class="mt-2 break-all text-sm text-slate-400">{{ $selectedUser->role }} @can('users.contact') &middot; {{ $selectedUser->email }} &middot; {{ $selectedUser->email_verified_at ? 'Email verified' : 'Email unverified' }} @endcan</p></div>
            @if (auth()->user()->can('users.status') && $selectedUser->role !== 'super_admin' && (auth()->user()->can('roles.manage') || in_array($selectedUser->role, ['users', 'agent'], true)))
                <button type="button" wire:click="toggleStatus" wire:confirm="Change this account's access status?" wire:loading.attr="disabled" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-600 dark:border-slate-700 dark:bg-surface-dark dark:text-slate-300">{{ $selectedUser->is_active ? 'Suspend account' : 'Reactivate account' }}</button>
            @endif
        </div>
        <div class="mb-6 grid grid-cols-2 gap-3 xl:grid-cols-4">
            @foreach ([...(auth()->user()->can('users.balances') ? ['Wallet balance' => \App\Money::format($selectedUser->wallet_balance)] : []), 'Account status' => $selectedUser->is_active ? 'Active' : 'Suspended', 'Registered' => $selectedUser->created_at->format('d M Y'), ...(auth()->user()->can('users.contact') ? ['Last sign-in' => $selectedUser->last_login_at?->format('d M Y H:i') ?? 'Not recorded'] : [])] as $label => $value)
                <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark"><p class="text-xs text-slate-400">{{ $label }}</p><p class="mt-2 break-words text-lg font-bold">{{ $value }}</p></div>
            @endforeach
        </div>
        @can('roles.manage')
        <section class="mb-6 rounded-3xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-surface-dark">
            <h2 class="text-xl font-semibold">Role and staff permissions</h2>
            <p class="mt-2 text-sm text-slate-400">Choose the sections, fields, and actions this staff member can use. Required view permissions are included automatically. Agents can transfer from their own wallet; users can receive transfers.</p>
            <form wire:submit="saveAccess" wire:confirm="Apply this role and these permissions?" class="mt-5 flex flex-col gap-5">
                <div><label for="user-role" class="mb-2 block text-sm font-medium">Role</label><select id="user-role" wire:model.live="role" class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-800">@foreach (\App\AdminAccess::ROLES as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</select></div>
                @if ($role === 'staff')<fieldset class="grid gap-3 sm:grid-cols-2"><legend class="mb-3 text-sm font-semibold">Staff access</legend>@foreach (\App\AdminAccess::permissions() as $permission => $label)<label class="flex items-center gap-3 text-sm"><input type="checkbox" wire:model="staffPermissions" value="{{ $permission }}" class="size-4 accent-indigo-600">{{ $label }}</label>@endforeach</fieldset>@endif
                <div><button type="submit" wire:loading.attr="disabled" class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">Save access</button></div>
            </form>
        </section>
        @endcan
        <div class="grid gap-6 xl:grid-cols-2">
            @if (auth()->user()->can('users.edit') && (auth()->user()->can('roles.manage') || in_array($selectedUser->role, ['users', 'agent'], true)))
            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark"><h2 class="mb-5 text-xl font-semibold">Profile details</h2><form wire:submit="saveProfile" class="flex flex-col gap-5"><div class="grid gap-4 sm:grid-cols-2">
<div><label for="user-firstName" class="mb-2 block text-sm font-medium">First name</label><input wire:model="firstName" placeholder="Enter first name" id="user-firstName" type="text" maxlength="100" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></div>
<div><label for="user-surname" class="mb-2 block text-sm font-medium">Surname</label><input wire:model="surname" placeholder="Enter surname" id="user-surname" type="text" maxlength="100" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></div>
<div><label for="user-email" class="mb-2 block text-sm font-medium">Email</label><input wire:model="email" placeholder="name@example.com" id="user-email" type="email" maxlength="255" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></div>
<div><label for="user-phone" class="mb-2 block text-sm font-medium">Phone</label><input wire:model="phone" placeholder="e.g. 08012345678" id="user-phone" type="tel" maxlength="30"  class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></div>
<div><label for="user-business" class="mb-2 block text-sm font-medium">Business</label><input wire:model="business" placeholder="Enter business name" id="user-business" type="text" maxlength="255"  class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></div>
            </div><div><label for="user-address" class="mb-2 block text-sm font-medium">Address</label><textarea placeholder="Enter the complete address" wire:model="address" id="user-address" rows="3" maxlength="1000" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></textarea></div><div><button type="submit" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50">Save profile</button></div></form></section>
            @elseif (auth()->user()->can('users.contact'))
                <section class="rounded-3xl border border-slate-200 p-6"><h2 class="font-semibold">Profile details</h2><dl class="mt-4 grid gap-3">@foreach (['Phone' => $selectedUser->phone, 'Business' => $selectedUser->business, 'Address' => $selectedUser->address] as $label => $value)<div><dt class="text-xs text-slate-400">{{ $label }}</dt><dd>{{ $value ?: 'Not provided' }}</dd></div>@endforeach</dl></section>
            @endif
            <div class="flex flex-col gap-6">
                @can('wallet.adjust')
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark"><h2 class="mb-2 text-xl font-semibold">Credit or debit wallet</h2><p class="mb-5 text-sm leading-6 text-slate-400">Every adjustment records the reason, administrator, and balance before and after. Debits cannot exceed the available balance.</p>
                    <form wire:submit="adjustWallet" wire:confirm="Record this wallet adjustment?" class="flex flex-col gap-4">
                        <div class="grid gap-4 sm:grid-cols-2"><div><label for="wallet-direction" class="mb-2 block text-sm font-medium">Adjustment</label><select wire:model="direction" id="wallet-direction" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"><option value="credit">Credit wallet</option><option value="debit">Debit wallet</option></select></div><div><label for="wallet-amount" class="mb-2 block text-sm font-medium">Amount</label><input wire:model="amount" id="wallet-amount" type="text" inputmode="decimal" required placeholder="0.00" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></div></div>
                        <div><label for="wallet-reason" class="mb-2 block text-sm font-medium">Reason</label><textarea placeholder="Explain the reason for this wallet adjustment" wire:model="reason" id="wallet-reason" rows="2" minlength="3" maxlength="500" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></textarea></div>
                        <div><button type="submit" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50">Record adjustment</button></div>
                    </form>
                </section>
                @endcan
                @if (auth()->user()->can('users.password') && (auth()->user()->can('roles.manage') || in_array($selectedUser->role, ['users', 'agent'], true)))
                <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark"><h2 class="mb-2 text-xl font-semibold">Reset password</h2><p class="mb-5 text-sm leading-6 text-slate-400">Set a new password with at least 8 characters. Existing sessions and remember-me access will be revoked. No email is sent.</p>
                    <form wire:submit="resetPassword" wire:confirm="Reset this user's password and revoke existing sessions?" class="flex flex-col gap-4">
                        <div><label for="reset-password" class="mb-2 block text-sm font-medium">New password</label><input wire:model="newPassword" placeholder="Enter a new password" id="reset-password" type="password" autocomplete="new-password" required minlength="8" maxlength="72" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></div>
                        <div><label for="reset-password-confirm" class="mb-2 block text-sm font-medium">Confirm new password</label><input wire:model="newPassword_confirmation" placeholder="Re-enter the new password" id="reset-password-confirm" type="password" autocomplete="new-password" required minlength="8" maxlength="72" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></div>
                        <div><button type="submit" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50">Reset password</button></div>
                    </form>
                </section>
                @endif
            </div>
        </div>
        @can('users.transactions')
        <section class="mt-7 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-surface-dark">
            <div class="border-b border-slate-100 p-6 dark:border-slate-800"><h2 class="text-xl font-semibold">Latest 20 transactions</h2><p class="mt-1 text-sm text-slate-400">Most recent first, for this user only.</p></div>
            <div class="overflow-x-auto"><table class="w-full min-w-6xl text-left text-sm"><caption class="sr-only">Latest 20 transactions for {{ $selectedUser->name }}</caption>
                <thead class="bg-slate-50 text-slate-500 dark:bg-slate-800/60 dark:text-muted-light"><tr>@foreach (['Transaction ID', 'Service', 'Amount', 'Old balance', 'New balance', 'Status', 'Administrator', 'Reason', 'Date'] as $heading)<th scope="col" class="whitespace-nowrap px-4 py-4 font-semibold">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($latestTransactions as $transaction)
                        <tr wire:key="transaction-{{ $transaction->id }}"><td class="px-4 py-4 font-mono">{{ $transaction->reference }}</td><td class="whitespace-nowrap px-4 py-4">{{ $transaction->service }}</td><td class="px-4 py-4 tabular-nums">{{ \App\Money::format($transaction->amount) }}</td><td class="px-4 py-4 tabular-nums">{{ \App\Money::format($transaction->old_balance) }}</td><td class="px-4 py-4 tabular-nums">{{ \App\Money::format($transaction->new_balance) }}</td><td class="px-4 py-4">{{ ucfirst($transaction->status) }}</td><td class="px-4 py-4">{{ $transaction->actor->name }}</td><td class="min-w-48 px-4 py-4">{{ $transaction->reason }}</td><td class="whitespace-nowrap px-4 py-4">{{ $transaction->created_at->format('d M Y H:i') }}</td></tr>
                    @empty <tr><td colspan="9" class="px-6 py-12 text-center text-slate-400">No transactions for this user yet.</td></tr> @endforelse
                </tbody>
            </table></div>
        </section>
        @endcan
        @can('users.activity')
        <section class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark"><h2 class="mb-4 text-xl font-semibold">Recent account activity</h2><div class="flex flex-col gap-3">
            @forelse ($userAudits as $audit)<div class="flex flex-col justify-between gap-1 text-xs sm:flex-row"><p><span class="font-semibold">{{ str_replace(['.', '_'], ' ', $audit->event) }}</span> &middot; {{ $audit->actor?->name ?? 'System' }}</p><span class="text-slate-400">{{ $audit->created_at->format('d M Y H:i') }}</span></div>@empty <p class="text-sm text-slate-400">No account activity recorded.</p> @endforelse
        </div></section>
        @endcan
    @endif
</x-admin-shell>
