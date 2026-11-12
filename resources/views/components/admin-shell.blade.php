@props(['title', 'section', 'requestService' => null, 'transactionGroup' => 'all'])
<div x-data="{ navOpen: false }" x-on:keydown.escape.window="navOpen = false" class="min-h-screen">
    <a href="#admin-main" class="sr-only z-50 rounded-xl bg-indigo-600 p-3 text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4">Skip to content</a>
    <div x-cloak x-show="navOpen" x-on:click="navOpen = false" class="fixed inset-0 z-40 bg-slate-950/50 backdrop-blur-sm lg:hidden" aria-hidden="true"></div>
    <aside aria-label="Admin navigation" x-bind:class="navOpen ? 'translate-x-0' : '-translate-x-full'" class="app-sidebar fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-white/10 bg-ink transition-transform lg:translate-x-0! dark:border-white/10 dark:bg-ink text-white">
        <div class="flex h-20 items-center justify-between border-b border-white/10 px-6 dark:border-white/10">
            <a href="{{ route('admin.dashboard') }}" class="min-w-0">
                <span class="block truncate text-lg font-bold">{{ config('app.name') }}</span>
                <span class="text-xs font-semibold uppercase tracking-widest text-indigo-300 dark:text-indigo-400">Administration</span>
            </a>
            <button type="button" x-on:click="navOpen = false" aria-label="Close admin navigation" class="rounded-lg p-2 text-sidebar-muted lg:hidden">&times;</button>
        </div>
        <nav class="flex flex-1 flex-col gap-2 overflow-y-auto p-4">
            @foreach (['overview' => ['Dashboard', 'admin.dashboard', '01'], 'prices' => ['Prices', 'admin.prices', '02'], 'settings' => ['Site settings', 'admin.settings', '03'], 'users' => ['User management', 'admin.users', '04']] as $key => $item)
                @if (($key === 'overview' && auth()->user()->can('dashboard.view')) || ($key !== 'overview' && auth()->user()->can($key.'.view')))
                <a href="{{ route($item[1]) }}" @if ($section === $key) aria-current="page" @endif @class([
                    'flex items-center gap-3 rounded-xl px-4 py-3.5 text-sm font-semibold transition',
                    'bg-indigo-50 text-indigo-700' => $section === $key,
                    'text-sidebar-muted hover:bg-white/5 hover:text-white dark:text-sidebar-muted dark:hover:bg-white/5' => $section !== $key,
                ])><span class="text-xs font-medium opacity-60" aria-hidden="true">{{ $item[2] }}</span>{{ $item[0] }}</a>
                @endif
            @endforeach
            @can('transactions.view')
                <details class="group" @if ($section === 'transactions') open @endif>
                    <summary @class([
                        'flex cursor-pointer list-none items-center gap-3 rounded-xl px-4 py-3.5 text-sm font-semibold transition [&::-webkit-details-marker]:hidden',
                        'bg-indigo-50 text-indigo-700' => $section === 'transactions',
                        'text-sidebar-muted hover:bg-white/5 hover:text-white' => $section !== 'transactions',
                    ])>
                        <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 7h16m-4-4 4 4-4 4M20 17H4m4-4-4 4 4 4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span class="flex-1">Transactions</span>
                        <svg class="size-4 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </summary>
                    <div class="mt-2 ml-6 flex flex-col gap-1 border-l border-white/15 pl-3">
                        @foreach (\App\Livewire\Admin\Transactions::GROUPS as $groupKey => $groupLabel)
                            <a href="{{ route('admin.transactions', ['transactionGroup' => $groupKey]) }}" @if ($section === 'transactions' && $transactionGroup === $groupKey) aria-current="page" @endif @class([
                                'rounded-xl px-4 py-3 text-sm font-semibold transition',
                                'bg-white/10 text-white' => $section === 'transactions' && $transactionGroup === $groupKey,
                                'text-sidebar-muted hover:bg-white/5 hover:text-white' => $section !== 'transactions' || $transactionGroup !== $groupKey,
                            ])>{{ $groupLabel }}</a>
                        @endforeach
                    </div>
                </details>
            @endcan
            @can('requests.view')
                <details class="group" @if ($section === 'requests') open @endif>
                    <summary @class([
                        'flex cursor-pointer list-none items-center gap-3 rounded-xl px-4 py-3.5 text-sm font-semibold transition [&::-webkit-details-marker]:hidden',
                        'bg-indigo-50 text-indigo-700' => $section === 'requests',
                        'text-sidebar-muted hover:bg-white/5 hover:text-white' => $section !== 'requests',
                    ])>
                        <span class="text-xs font-medium opacity-60" aria-hidden="true">05</span>
                        <span class="flex-1">Service requests</span>
                        <svg class="size-4 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </summary>
                    <div class="mt-2 flex flex-col gap-1 border-l border-white/15 pl-3 ml-6">
                        @foreach (\App\Models\ServiceRequest::ADMIN_SERVICES as $requestServiceKey)
                            <a href="{{ route('admin.requests.service', ['service' => $requestServiceKey]) }}" @if ($section === 'requests' && $requestService === $requestServiceKey) aria-current="page" @endif @class([
                                'rounded-xl px-4 py-3 text-sm font-semibold transition',
                                'bg-white/10 text-white' => $section === 'requests' && $requestService === $requestServiceKey,
                                'text-sidebar-muted hover:bg-white/5 hover:text-white' => $section !== 'requests' || $requestService !== $requestServiceKey,
                            ])>{{ \App\ServiceCatalog::requestServices()[$requestServiceKey]['label'] }}</a>
                        @endforeach
                    </div>
                </details>
            @endcan
            <details class="group">
                <summary class="flex cursor-pointer list-none items-center gap-3 rounded-xl px-4 py-3.5 text-sm font-semibold text-sidebar-muted transition hover:bg-white/5 hover:text-white [&::-webkit-details-marker]:hidden">
                    <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span class="flex-1">NOTIFICATION</span>
                    <svg class="size-4 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </summary>
                <div class="mt-2 ml-6 flex flex-col gap-1 border-l border-white/15 pl-3">
                    <button type="button" disabled title="Email notifications coming soon" class="cursor-not-allowed rounded-xl px-4 py-3 text-left text-sm font-semibold text-sidebar-muted opacity-60">Email</button>
                    <button type="button" disabled title="Phone notifications coming soon" class="cursor-not-allowed rounded-xl px-4 py-3 text-left text-sm font-semibold text-sidebar-muted opacity-60">Phone</button>
                </div>
            </details>
        </nav>
        <div class="flex flex-col gap-2 border-t border-white/10 p-4 dark:border-white/10">
            @can('wallet.transfer')<a href="{{ route('wallet.transfer') }}" class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold text-sidebar-muted hover:bg-white/5 hover:text-white dark:text-sidebar-muted dark:hover:bg-white/5 dark:hover:text-white"><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 7h16m-4-4 4 4-4 4M20 17H4m4-4-4 4 4 4" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Transfer from wallet</span></a>@endcan
            <a href="{{ route('dashboard') }}" class="rounded-xl px-4 py-3 text-sm font-medium text-sidebar-muted hover:bg-white/5 dark:hover:bg-white/5">Back to website</a>
            <button type="button" wire:click="logout" class="rounded-xl px-4 py-3 text-left text-sm font-semibold text-red-300 hover:bg-red-500/10 dark:text-red-400 dark:hover:bg-red-500/100/10">Sign out</button>
        </div>
    </aside>
    <div class="lg:pl-72">
        <header class="sticky top-0 z-30 flex h-20 items-center justify-between gap-4 border-b border-slate-200 bg-white/95 px-4 backdrop-blur-sm sm:px-8 dark:border-slate-800 dark:bg-surface-dark/95">
            <div class="flex items-center gap-3">
                <button type="button" x-on:click="navOpen = true" aria-label="Open admin navigation" x-bind:aria-expanded="navOpen" class="rounded-xl border border-slate-200 p-2.5 lg:hidden dark:border-slate-700">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round"/></svg>
                </button>
                <span class="text-sm font-semibold">{{ $title }}</span>
                <span class="hidden rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-bold uppercase tracking-wider text-indigo-600 sm:inline dark:bg-indigo-500/10 dark:text-indigo-300">Admin</span>
            </div>
            @include('livewire.account-menu')
        </header>
        <main id="admin-main" class="mx-auto max-w-7xl px-4 py-7 sm:px-8 sm:py-9">
            @if (session('status'))
                <p role="status" class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <ul role="alert" class="mb-6 flex flex-col gap-1 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            @endif
            {{ $slot }}
        </main>
    </div>
</div>
