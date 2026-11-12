<div x-data="{ sidebarOpen: false, desktop: window.matchMedia('(min-width: 64rem)').matches }" x-on:resize.window="desktop = window.matchMedia('(min-width: 64rem)').matches; if (desktop) sidebarOpen = false" x-on:keydown.escape.window="sidebarOpen = false" class="min-h-screen">
    <a href="#main-content" class="sr-only z-50 rounded-lg bg-indigo-600 p-3 text-white focus:not-sr-only focus:fixed focus:left-4 focus:top-4">Skip to content</a>
    <div x-cloak x-show="sidebarOpen && !desktop" x-transition.opacity x-on:click="sidebarOpen = false" class="fixed inset-0 z-40 bg-slate-950/50 backdrop-blur-sm" aria-hidden="true"></div>

    <aside id="dashboard-sidebar" x-cloak x-show="sidebarOpen || desktop" x-trap.inert.noscroll="sidebarOpen && !desktop" x-bind:role="desktop ? null : 'dialog'" x-bind:aria-modal="sidebarOpen && !desktop ? 'true' : null" aria-label="Sidebar navigation" class="app-sidebar fixed inset-y-0 left-0 z-50 flex w-72 max-w-[85vw] flex-col border-r border-white/10 bg-ink text-white dark:border-white/10 dark:bg-ink">
        <div class="flex h-20 shrink-0 items-center justify-between gap-3 border-b border-white/10 px-6 dark:border-white/10">
            <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3 font-bold tracking-tight">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-lg shadow-indigo-500/20" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" class="size-6"><path d="m12 3 8 5-8 5-8-5 8-5Zm-8 9 8 5 8-5M4 16l8 5 8-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span class="truncate text-lg">{{ config('app.name') }}</span>
            </a>
            <button type="button" x-on:click="sidebarOpen = false" class="rounded-lg p-2 text-sidebar-muted hover:bg-white/5 focus-visible:outline-2 focus-visible:outline-indigo-500 lg:hidden dark:hover:bg-white/5" aria-label="Close sidebar">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6" stroke-linecap="round"/></svg>
            </button>
        </div>
        <nav aria-label="Main navigation" class="flex-1 overflow-y-auto px-4 py-6">
            <a href="{{ route('dashboard') }}" @if ($this->pageTitle === 'Dashboard') aria-current="page" @endif @class([
                'flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold',
                'bg-indigo-50 text-indigo-700' => $this->pageTitle === 'Dashboard',
                'text-sidebar-muted hover:bg-white/5 hover:text-white dark:text-sidebar-muted dark:hover:bg-white/5 dark:hover:text-white' => $this->pageTitle !== 'Dashboard',
            ])>
                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
                Dashboard
            </a>
            <a href="{{ route('transactions') }}" @if ($showTransactions) aria-current="page" @endif @class([
                'mt-1 flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold',
                'bg-indigo-50 text-indigo-700' => $showTransactions,
                'text-sidebar-muted hover:bg-white/5 hover:text-white dark:text-sidebar-muted dark:hover:bg-white/5 dark:hover:text-white' => ! $showTransactions,
            ])>
                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Zm3 5h6m-6 4h6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Transactions
            </a>
            <a href="{{ route('complaints') }}" @if ($showComplaints) aria-current="page" @endif @class([
                'mt-1 flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold',
                'bg-indigo-50 text-indigo-700' => $showComplaints,
                'text-sidebar-muted hover:bg-white/5 hover:text-white dark:text-sidebar-muted dark:hover:bg-white/5 dark:hover:text-white' => ! $showComplaints,
            ])>
                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Complains
            </a>
            @can('wallet.transfer')
                <a href="{{ route('wallet.transfer') }}" @if ($showTransfer) aria-current="page" @endif @class([
                    'mt-1 flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold',
                    'bg-indigo-50 text-indigo-700' => $showTransfer,
                    'text-sidebar-muted hover:bg-white/5 hover:text-white dark:text-sidebar-muted dark:hover:bg-white/5 dark:hover:text-white' => ! $showTransfer,
                ])><svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M4 7h16m-4-4 4 4-4 4M20 17H4m4-4-4 4 4 4" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Transfer from wallet</span></a>
            @endcan
            @can('access-admin')
                <a href="{{ route('admin.dashboard') }}" class="mt-1 flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold text-sidebar-muted hover:bg-white/5 hover:text-white dark:text-sidebar-muted dark:hover:bg-white/5 dark:hover:text-white">
                    <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m12 3 8 4v5c0 4-4 7-8 9-4-2-8-5-8-9V7l8-4Z" stroke-linejoin="round"/><path d="m8 12 3 3 5-6" stroke-linecap="round"/></svg>
                    Admin dashboard
                </a>
            @endcan
            <p class="mb-3 mt-8 px-4 text-xs font-bold tracking-[0.18em] text-sidebar-muted">SERVICES</p>
            <div class="flex flex-col gap-1">
                @foreach ($this->navigationServices as $serviceId => $service)
                    @if (isset($this->unavailableServices[$serviceId]))
                        <a wire:key="nav-{{ $serviceId }}" href="{{ route($this->verificationRoutes[$serviceId]) }}" x-on:click="sidebarOpen = false" class="flex w-full items-center gap-3 rounded-xl px-4 py-2.5 text-left text-sm font-medium text-sidebar-muted hover:bg-white/5 hover:text-white">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-white/5" aria-hidden="true">@include('livewire.service-icon', ['serviceId' => $serviceId, 'iconClass' => 'size-3.5', 'imageClass' => 'size-6'])</span>
                            <span>{{ $service['name'] }}<span class="mt-1 block text-xs text-red-400">Temporarily unavailable</span></span>
                        </a>
                    @elseif (isset($this->verificationRoutes[$serviceId]))
                    <a wire:key="nav-{{ $serviceId }}" href="{{ route($this->verificationRoutes[$serviceId]) }}" x-on:click="sidebarOpen = false" @if ($serviceId === 'nin-'.$ninVersion || ($isBvnVerification && $serviceId === 'bvn-verification') || $serviceId === $ipeService || ($isBvnRetrieval && $serviceId === 'bvn retrival') || ($isNinValidation && $serviceId === 'validations') || ($isDelinking && $serviceId === 'delinking') || ($isNinModification && $serviceId === 'nin-modification') || ($isPersonalization && $serviceId === 'personalization') || ($isBvnModification && $serviceId === 'bvn-modification') || ($isBvnLicenseOnboarding && $serviceId === 'bvn-license-onboarding')) aria-current="page" @endif class="flex w-full items-center gap-3 rounded-xl px-4 py-2.5 text-left text-sm font-medium text-sidebar-muted transition hover:bg-white/5 hover:text-white focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-sidebar-muted dark:hover:bg-white/5 dark:hover:text-white">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-white/5 text-sidebar-muted dark:bg-white/5 dark:text-sidebar-muted" aria-hidden="true">
                            @include('livewire.service-icon', ['serviceId' => $serviceId, 'iconClass' => 'size-3.5', 'imageClass' => 'size-6'])
                        </span>
                        {{ $service['name'] }}
                    </a>
                    @else
                    <button wire:key="nav-{{ $serviceId }}" wire:click="selectService('{{ $serviceId }}')" x-on:click="sidebarOpen = false" type="button" class="flex w-full items-center gap-3 rounded-xl px-4 py-2.5 text-left text-sm font-medium text-sidebar-muted transition hover:bg-white/5 hover:text-white focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-sidebar-muted dark:hover:bg-white/5 dark:hover:text-white">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-lg bg-white/5 text-sidebar-muted dark:bg-white/5 dark:text-sidebar-muted" aria-hidden="true">
                            @include('livewire.service-icon', ['serviceId' => $serviceId, 'iconClass' => 'size-3.5', 'imageClass' => 'size-6'])
                        </span>
                        {{ $service['name'] }}
                    </button>
                    @endif
                @endforeach
            </div>
        </nav>
        <div class="border-t border-white/10 p-4 dark:border-white/10">
            <div class="mb-3 rounded-xl bg-white/5 px-4 py-3 dark:bg-white/5">
                <p class="text-xs font-semibold uppercase tracking-wider text-sidebar-muted">Your business</p>
                <p class="mt-1 truncate text-sm font-semibold">{{ auth()->user()->business ?: auth()->user()->name }}</p>
            </div>
            <button wire:click="logout" wire:loading.attr="disabled" wire:target="logout" type="button" class="flex w-full items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium text-sidebar-muted transition hover:bg-red-500/10 hover:text-red-300 disabled:opacity-50 dark:hover:bg-red-500/100/10 dark:hover:text-red-400">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M9 5H5v14h4m5-11 4 4-4 4m-6-4h10" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Sign out
            </button>
        </div>
    </aside>

    <div class="lg:pl-72">
        <header class="sticky top-0 z-30 flex h-20 items-center justify-between gap-4 border-b border-slate-200/80 bg-white/95 px-5 backdrop-blur-sm sm:px-8 dark:border-slate-800 dark:bg-surface-dark/95">
            <div class="flex min-w-0 items-center gap-3">
                <button type="button" x-on:click="sidebarOpen = !sidebarOpen" x-bind:aria-expanded="sidebarOpen" aria-controls="dashboard-sidebar" aria-label="Open sidebar" class="rounded-xl border border-slate-200 p-2.5 text-slate-600 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-indigo-500 lg:hidden dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round"/></svg>
                </button>
                <span class="truncate text-sm font-semibold">{{ $this->pageTitle }}</span>
                <span class="hidden text-slate-300 sm:inline dark:text-slate-600">/</span>
                <span class="hidden text-sm text-slate-400 sm:inline">{{ $showTransactions ? 'History' : ($ipeService !== '' || $isBvnRetrieval || $isNinValidation || $isDelinking ? 'Request' : ($ninVersion === '' && ! $isBvnVerification ? 'Overview' : 'Verification')) }}</span>
            </div>
            @include('livewire.account-menu')
        </header>

        <main id="main-content" class="mx-auto w-full max-w-6xl px-4 pb-28 pt-6 sm:px-6 lg:px-8 lg:pt-8">
            @if (session('status'))
                <p role="status" class="mb-6 rounded-xl border border-emerald-100 bg-emerald-50 px-5 py-4 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300">{{ session('status') }}</p>
            @endif
            @include('livewire.service-unavailable')
            @if ($showComplaints)
                <livewire:complaints />
            @elseif ($isBvnLicenseOnboarding)
                <livewire:bvn-license-onboarding />
            @elseif ($isBvnModification)
                <livewire:bvn-modification />
            @elseif ($isPersonalization)
                <livewire:personalization />
            @elseif ($isNinModification)
                @if ($modificationStage !== '')
                    <livewire:nin-modification :stage="$modificationStage" :key="'nin-modification-'.$modificationStage" />
                @else
                    @include('livewire.nin-modification-services')
                @endif
            @elseif ($showTransfer)
                <livewire:wallet-transfer-form />
            @elseif ($showTransactions)
                @include('livewire.transaction-history')
            @elseif ($isDelinking)
                <livewire:delinking />
            @elseif ($isNinValidation)
                <livewire:nin-validation />
            @elseif ($isBvnRetrieval)
                <livewire:bvn-retrieval />
            @elseif ($ipeService === 'instant-ipe')
                <livewire:instant-ipe />
            @elseif ($ipeService !== '')
                <livewire:ipe-request :service="$ipeService" :key="$ipeService" />
            @elseif ($isBvnVerification)
                <livewire:bvn-verification />
            @elseif ($ninVersion !== '')
                <livewire:nin-verification :version="$ninVersion" :key="'nin-'.$ninVersion" />
            @else
            <div class="mx-auto max-w-5xl">
            <div class="mb-10 grid grid-cols-1 items-stretch gap-5 xl:grid-cols-12">
                <section x-data aria-labelledby="wallet-heading" class="relative flex min-h-72 min-w-0 flex-col justify-between overflow-hidden rounded-3xl xl:col-span-5 bg-ink p-6 text-white shadow-xl shadow-slate-400/30 sm:p-8 dark:shadow-black/40">
                    <div class="pointer-events-none absolute inset-0 bg-gradient-to-br from-white/[0.04] via-transparent to-indigo-500/10" aria-hidden="true"></div>
                    <div class="pointer-events-none absolute right-0 top-0 size-80 rounded-full bg-indigo-500/10 blur-3xl" aria-hidden="true"></div>
                    <h1 id="wallet-heading" class="break-words text-2xl font-bold tracking-tight sm:text-3xl">Welcome back, {{ auth()->user()->first_name ?: auth()->user()->name }}.</h1>
                    <div class="relative z-10 mt-8 flex flex-col gap-6">
                        <div>
                            <p class="text-sm font-medium text-slate-300">Wallet balance</p>
                            <p class="mt-2 break-words text-4xl font-bold tracking-tight tabular-nums">{{ \App\Money::format($walletBalance) }}</p>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <button type="button" x-on:click="$refs.walletDialog.showModal()" class="flex flex-1 basis-44 items-center justify-center gap-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 px-4 py-3 text-sm font-semibold shadow-lg transition focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-300 active:scale-[0.99]">
                                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v8m-4-4h8" stroke-linecap="round"/></svg>
                                Fund Wallet
                            </button>
                            <a href="{{ route('transactions') }}" class="flex flex-1 basis-44 items-center justify-center gap-2 rounded-xl bg-white/10 px-4 py-3 text-sm font-semibold backdrop-blur-md transition hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-300">
                                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Zm3 5h6m-6 4h6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                View Transactions
                                <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </a>
                        </div>
                    </div>
                    <dialog x-ref="walletDialog" aria-labelledby="wallet-dialog-heading" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-slate-900 shadow-2xl backdrop:bg-slate-950/50 backdrop:backdrop-blur-sm dark:border-slate-700 dark:bg-surface-dark dark:text-slate-100">
                        <h2 id="wallet-dialog-heading" class="text-xl font-bold">Fund Wallet</h2>
                        <p class="mt-3 text-sm leading-6 text-slate-500 dark:text-muted-light">Wallet funding is not available yet. Please check back soon.</p>
                        <form method="dialog" class="mt-6">
                            <button type="submit" autofocus class="w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500">Back to dashboard</button>
                        </form>
                    </dialog>
                </section>
                <div class="flex min-w-0 flex-col gap-5 xl:col-span-7">
                    <section aria-label="Today's verification activity" class="grid grid-cols-2 gap-3 sm:gap-5">
                        <article class="flex min-w-0 items-start justify-between gap-3 rounded-3xl border border-slate-200/70 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark dark:shadow-black/25">
                            <div>
                                <h2 class="text-xs font-medium leading-5 text-slate-500 sm:text-sm dark:text-muted-light">Total verifications today</h2>
                                <p class="mt-3 text-4xl font-bold tracking-tight tabular-nums">0</p>
                                <p class="mt-2 text-xs text-slate-400">No verifications yet today</p>
                            </div>
                            <span class="hidden size-10 shrink-0 items-center justify-center rounded-xl sm:flex bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300" aria-hidden="true">
                                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M9 5H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3" stroke-linecap="round"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="m8 13 3 3 5-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                        </article>
                        <article class="flex min-w-0 items-start justify-between gap-3 rounded-3xl border border-slate-200/70 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark dark:shadow-black/25">
                            <div>
                                <h2 class="text-xs font-medium leading-5 text-slate-500 sm:text-sm dark:text-muted-light">Failed verifications today</h2>
                                <p class="mt-3 text-4xl font-bold tracking-tight tabular-nums">0</p>
                                <p class="mt-2 text-xs text-slate-400">No failed verifications today</p>
                            </div>
                            <span class="hidden size-10 shrink-0 items-center justify-center rounded-xl sm:flex bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400" aria-hidden="true">
                                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m-6 0 6-6" stroke-linecap="round"/></svg>
                            </span>
                        </article>
                    </section>

                    <section aria-labelledby="announcements-heading" class="flex flex-1 flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-lg shadow-slate-200/40 dark:border-slate-800 dark:bg-surface-dark dark:shadow-black/20">
                        <div class="flex items-center gap-3 border-b border-slate-200/80 px-6 py-4 dark:border-slate-800">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white/70 text-indigo-600 dark:border-slate-800 dark:bg-white/5 dark:text-indigo-300" aria-hidden="true">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m15 5-9 4H3v6h3l9 4V5Zm0 3h3a4 4 0 0 1 0 8h-3M6 15l2 6h3l-2-5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <div>
                                <h2 id="announcements-heading" class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Announcement</h2>
                            </div>
                        </div>
                        <div class="flex flex-1 flex-col justify-center px-6 py-5">
                            @if (config('site.announcement'))
                                <p class="whitespace-pre-line break-words text-sm leading-6 text-slate-600 dark:text-slate-300">{{ config('site.announcement') }}</p>
                            @else
                                <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">No announcements at the moment</p>
                                <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-muted-light">Service updates and important notices will appear here. Check back for the latest news.</p>
                            @endif
                        </div>
                    </section>
                </div>
            </div>

            <section aria-labelledby="services-heading" class="mx-auto max-w-5xl">
                <div class="mb-5 flex flex-col gap-4 sm:mb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 id="services-heading" class="text-xl font-bold tracking-tight sm:text-2xl">Explore services</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-muted-light">Select a service to continue.</p>
                    </div>

                </div>
                <div class="grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-3" aria-live="polite" aria-label="Service results">
                    @foreach ($this->services as $serviceId => $service)
                        <article wire:key="card-{{ $serviceId }}" @class([
                            'group relative flex aspect-square min-w-0 flex-col items-center gap-2 overflow-hidden rounded-3xl border border-slate-200/80 bg-white p-2.5 text-center shadow-sm transition duration-200 motion-reduce:transform-none sm:gap-4 sm:p-5 dark:border-slate-800 dark:bg-surface-dark dark:shadow-black/20',
                            'hover:-translate-y-1 hover:border-indigo-300 hover:shadow-xl dark:hover:border-indigo-500/40',
                        ])>
                            <span class="relative flex min-h-0 w-full flex-1 items-center justify-center text-indigo-600 dark:text-indigo-300" aria-hidden="true">
                                @include('livewire.service-icon', ['serviceId' => $serviceId, 'iconClass' => 'size-4 sm:size-5', 'imageClass' => 'absolute inset-0 m-auto h-[85%] w-[85%] rounded-2xl object-center'])
                            </span>
                            <div class="flex w-full shrink-0 flex-col items-center gap-1 pb-1 sm:gap-2 sm:pb-0">
                                <h3 class="max-w-full break-words text-xl font-bold leading-7 tracking-tight sm:text-2xl sm:leading-8">{{ $service['name'] }}</h3>
                                <span class="inline-flex items-center justify-center gap-1.5 text-xs font-medium text-slate-500 transition group-hover:text-indigo-600 sm:text-xs dark:text-muted-light dark:group-hover:text-indigo-300">
                                    {{ isset($this->unavailableServices[$serviceId]) ? 'Temporarily unavailable' : (isset($this->verificationRoutes[$serviceId]) ? 'Open service' : 'Coming soon') }}
                                    <svg class="size-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 12h14m-5-5 5 5-5 5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                            </div>
                            @if (isset($this->unavailableServices[$serviceId]))
                                <span class="absolute right-2 top-2 rounded-full bg-red-50 px-2 py-1 text-xs font-medium text-red-700 dark:bg-red-950 dark:text-red-300">Temporarily unavailable</span>
                            @endif
                            @if (isset($this->verificationRoutes[$serviceId]))
                                <a href="{{ route($this->verificationRoutes[$serviceId]) }}" aria-label="View {{ $service['name'] }}" class="absolute inset-0 rounded-3xl focus-visible:outline-2 focus-visible:-outline-offset-4 focus-visible:outline-indigo-500"></a>
                            @else
                                <button wire:click="selectService('{{ $serviceId }}')" type="button" aria-label="View {{ $service['name'] }}" class="absolute inset-0 rounded-3xl focus-visible:outline-2 focus-visible:-outline-offset-4 focus-visible:outline-indigo-500"></button>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
            </div>
            @endif
            <footer class="mt-10 border-t border-slate-200/80 pt-6 text-xs text-slate-400 dark:border-slate-800">&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</footer>
        </main>
    </div>

    <div x-data="{ chatOpen: false }" x-on:keydown.escape.window="chatOpen = false" x-on:click.outside="chatOpen = false" class="fixed bottom-6 left-6 z-30 lg:left-80">
        <section id="whatsapp-support-panel" x-cloak x-show="chatOpen" x-transition.opacity aria-label="WhatsApp support" class="mb-4 w-80 max-w-[calc(100vw-3rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-400/40 dark:border-slate-700 dark:bg-surface-dark dark:shadow-black/50">
            <div class="flex items-center justify-between gap-3 bg-emerald-600 px-5 py-4 text-white">
                <div class="min-w-0">
                    <p class="truncate text-sm font-bold">{{ config('app.name') }} support</p>
                    <p class="mt-0.5 text-xs text-emerald-100">Let's chat on WhatsApp</p>
                </div>
                <button type="button" x-on:click="chatOpen = false" aria-label="Close WhatsApp support" class="rounded-lg p-1.5 transition hover:bg-white/15 focus-visible:outline-2 focus-visible:outline-white">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M6 18 18 6" stroke-linecap="round"/></svg>
                </button>
            </div>
            <div class="p-5">
                <p class="text-sm font-semibold">How can we help?</p>
                <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-muted-light">Start a conversation with our support team on WhatsApp.</p>
                @if (config('site.support_email'))<p class="mt-3 break-all text-xs text-slate-500 dark:text-muted-light">Email: {{ config('site.support_email') }}</p>@endif
                @if (config('site.support_phone'))<p class="mt-2 text-xs text-slate-500 dark:text-muted-light">Phone: {{ config('site.support_phone') }}</p>@endif
                @php
                    $whatsappNumber = preg_replace('/\D+/', '', (string) config('services.whatsapp.number'));
                @endphp
                @if ($whatsappNumber !== '')
                    <a href="https://wa.me/{{ $whatsappNumber }}" target="_blank" rel="noopener noreferrer" class="mt-5 flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-500">
                        Chat on WhatsApp <span class="sr-only">(opens in a new tab)</span>
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </a>
                @else
                    <p class="mt-4 rounded-xl bg-slate-50 p-3 text-xs leading-5 text-slate-500 dark:bg-slate-800 dark:text-muted-light">WhatsApp support will be available soon.</p>
                @endif
            </div>
        </section>
        <button type="button" x-on:click="chatOpen = !chatOpen" x-bind:aria-expanded="chatOpen" aria-controls="whatsapp-support-panel" aria-label="Open WhatsApp support" class="flex size-14 items-center justify-center rounded-full bg-[#25D366] text-white shadow-xl shadow-emerald-900/25 transition hover:scale-105 hover:bg-emerald-500 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-500 motion-reduce:transform-none">
            <svg class="size-8" viewBox="0 0 32 32" fill="currentColor" aria-hidden="true"><path d="M16.04 3a12.87 12.87 0 0 0-11.1 19.4L3.1 29l6.77-1.78A12.94 12.94 0 1 0 16.04 3Zm0 23.57a10.7 10.7 0 0 1-5.46-1.5l-.4-.24-4.02 1.06 1.07-3.91-.26-.42a10.75 10.75 0 1 1 9.07 5.01Zm5.9-8.02c-.32-.16-1.9-.94-2.2-1.05-.3-.1-.51-.16-.73.16-.21.32-.83 1.05-1.02 1.27-.19.21-.38.24-.7.08-.32-.16-1.36-.5-2.58-1.6-.96-.85-1.6-1.9-1.79-2.22-.19-.32-.02-.5.14-.66.14-.14.32-.38.48-.56.16-.19.21-.32.32-.54.1-.21.05-.4-.03-.56-.08-.16-.72-1.75-.99-2.4-.26-.63-.53-.54-.73-.55h-.62c-.21 0-.56.08-.86.4-.3.33-1.12 1.1-1.12 2.67s1.15 3.1 1.31 3.31c.16.22 2.26 3.46 5.48 4.85.77.33 1.37.53 1.84.68.77.24 1.47.2 2.02.12.62-.1 1.9-.78 2.17-1.53.27-.75.27-1.39.19-1.52-.08-.14-.3-.22-.62-.38Z"/></svg>
        </button>
    </div>

    @if ($this->selectedService)
        <dialog wire:key="service-dialog-{{ $selectedServiceId }}" x-data x-init="$el.showModal()" x-on:cancel.prevent="$wire.closeService()" wire:click.self="closeService" aria-labelledby="service-dialog-title" aria-describedby="service-dialog-description" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-slate-200 bg-white p-8 text-slate-900 shadow-2xl backdrop:bg-slate-950/50 backdrop:backdrop-blur-sm dark:border-slate-700 dark:bg-surface-dark dark:text-slate-100">
            <span class="mb-5 inline-flex rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300">{{ isset($this->verificationRoutes[$serviceId]) ? 'Open form' : 'Coming soon' }}</span>
            <h2 id="service-dialog-title" class="text-xl font-bold">{{ $this->selectedService['name'] }}</h2>
            <p id="service-dialog-description" class="mt-3 text-sm leading-6 text-slate-500 dark:text-muted-light">This service is not available yet. Please check back soon.</p>
            <button wire:click="closeService" type="button" autofocus class="mt-6 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500">Back to dashboard</button>
        </dialog>
    @endif
</div>
