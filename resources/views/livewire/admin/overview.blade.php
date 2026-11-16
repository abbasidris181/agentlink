<x-admin-shell title="Dashboard" section="overview">
    <div class="page-banner mb-7">
        <p class="text-xs font-semibold uppercase tracking-widest text-indigo-600 dark:text-indigo-400">Overview</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight">Admin dashboard</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-muted-light">Your accounts, wallet activity, and service configuration in one place.</p>
    </div>
    @can('dashboard.view')
    <div class="mb-7 grid grid-cols-2 gap-4 xl:grid-cols-4">
        @foreach ($metrics as $label => $value)
            <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark"><h2 class="text-xs font-medium text-slate-500 dark:text-muted-light">{{ $label }}</h2><p class="mt-3 break-words text-4xl font-bold tracking-tight tabular-nums">{{ $label === 'Wallet balances' ? '₦'.$value : $value }}</p></article>
        @endforeach
    </div>
    @can('prices.view')
    <section class="mb-7 flex flex-col justify-between gap-4 rounded-3xl bg-indigo-600 p-6 text-white sm:flex-row sm:items-center">
        <div><h2 class="text-xl font-semibold">Service pricing</h2><p class="mt-1 text-sm text-indigo-100">{{ $pricedCount }} of {{ $priceCount }} sub-services have a configured price.</p></div>
        <a href="{{ route('admin.prices') }}" class="shrink-0 rounded-xl bg-white px-5 py-3 text-center text-sm font-semibold text-indigo-700">Manage prices</a>
    </section>
    @endcan
    <div class="grid gap-6 xl:grid-cols-2">
        @can('users.view')
        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark">
            <div class="mb-5 flex items-center justify-between gap-3"><h2 class="text-xl font-semibold">Recent registrations</h2><a href="{{ route('admin.users') }}" class="text-xs font-semibold text-indigo-600 dark:text-indigo-300">View all users</a></div>
            <div class="flex flex-col divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($recentUsers as $user)
                    <a href="{{ route('admin.users.show', ['userId' => $user->id]) }}" class="flex items-center justify-between gap-3 py-4"><div class="min-w-0"><p class="truncate text-sm font-semibold">{{ $user->name }}</p>@can('users.contact')<p class="mt-1 truncate text-xs text-slate-400">{{ $user->email }}</p>@endcan</div><span class="text-xs text-slate-400">{{ $user->created_at->format('d M Y') }}</span></a>
                @empty <p class="py-6 text-sm text-slate-400">No users yet.</p> @endforelse
            </div>
        </section>
        @endcan
        @can('users.activity')
        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark">
            <h2 class="mb-5 text-xl font-semibold">Recent admin activity</h2>
            <div class="flex flex-col divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($recentActivity as $activity)
                    <div class="py-4"><p class="text-sm font-semibold">{{ str_replace(['.', '_'], ' ', $activity->event) }}</p><p class="mt-1 text-xs leading-5 text-slate-400">{{ $activity->actor?->name ?? 'System' }} @if ($activity->user) &middot; {{ $activity->user->name }} @endif &middot; {{ $activity->created_at->format('d M Y H:i') }}</p></div>
                @empty <p class="py-6 text-sm text-slate-400">No admin activity yet.</p> @endforelse
            </div>
        </section>
        @endcan
    </div>
    @else
        <p class="rounded-2xl border border-slate-200 p-6 text-sm dark:border-slate-800">Use your assigned sections in the navigation. Contact a super administrator if you need additional access.</p>
    @endcan
</x-admin-shell>
