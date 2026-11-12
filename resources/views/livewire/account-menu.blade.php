<div x-data="{ accountOpen: false, theme: window.appTheme.preference }" x-on:app-theme-changed.window="theme = $event.detail" x-on:click.outside="accountOpen = false" x-on:keydown.escape="if (accountOpen) { $event.stopPropagation(); $event.preventDefault(); accountOpen = false; $refs.accountTrigger.focus(); }" class="relative min-w-0">
    <button x-ref="accountTrigger" type="button" x-on:click="accountOpen = !accountOpen" x-bind:aria-expanded="accountOpen" aria-controls="account-dropdown" aria-label="Open account options" class="flex min-w-0 items-center gap-3 rounded-xl p-2 text-left transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:hover:bg-slate-800">
        <span class="hidden min-w-0 text-right sm:block">
            <span class="block max-w-48 truncate text-sm font-semibold">{{ auth()->user()->name }}</span>
            <span class="block max-w-48 truncate text-xs text-slate-400">{{ auth()->user()->email }}</span>
        </span>
        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-sm font-bold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name, 0, 1)) }}</span>
        <svg class="size-4 shrink-0 text-slate-400 transition-transform" x-bind:class="accountOpen ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>

    <div id="account-dropdown" x-cloak x-show="accountOpen" x-transition.opacity class="absolute right-0 top-full z-40 mt-3 max-h-[calc(100dvh-7rem)] w-72 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-300/40 dark:border-slate-700 dark:bg-surface-dark dark:shadow-black/50">
        <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p>
            <p class="mt-1 truncate text-xs text-slate-400">{{ auth()->user()->email }}</p>
        </div>
        <nav aria-label="Account options" class="flex flex-col gap-1 p-2">
            <button type="button" x-on:click="accountOpen = false; $refs.profileDialog.showModal()" class="flex items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-medium text-slate-600 transition hover:bg-indigo-50 hover:text-indigo-600 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-indigo-300">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0" stroke-linecap="round"/></svg>
                Profile
            </button>
            <a href="{{ route('transactions') }}" x-on:click="accountOpen = false" class="flex items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-medium text-slate-600 transition hover:bg-indigo-50 hover:text-indigo-600 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-indigo-300">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Zm3 5h6m-6 4h6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Transactions
            </a>
        </nav>
        <fieldset class="border-y border-slate-100 px-4 pb-4 dark:border-slate-800">
            <legend class="px-1 text-xs font-semibold text-slate-400">Appearance</legend>
            <div class="mt-3 grid grid-cols-3 gap-2">
                @foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $themeValue => $themeLabel)
                    <button type="button" x-on:click="window.appTheme.set('{{ $themeValue }}')" x-bind:aria-pressed="theme === '{{ $themeValue }}'" x-bind:class="theme === '{{ $themeValue }}' ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-500/15 dark:text-indigo-300' : 'border-slate-200 text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:text-muted-light dark:hover:bg-slate-800'" class="flex flex-col items-center gap-2 rounded-xl border px-2 py-3 text-xs font-medium transition focus-visible:outline-2 focus-visible:outline-indigo-500">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            @if ($themeValue === 'light')
                                <circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>
                            @elseif ($themeValue === 'dark')
                                <path d="M20.5 13.5a9 9 0 0 1-10-10 9 9 0 1 0 10 10Z"/>
                            @else
                                <rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4"/>
                            @endif
                        </svg>
                        {{ $themeLabel }}
                    </button>
                @endforeach
            </div>
        </fieldset>
        <div class="p-2">
            <button wire:click="logout" wire:loading.attr="disabled" wire:target="logout" type="button" class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-medium text-red-600 transition hover:bg-red-50 disabled:opacity-50 dark:text-red-400 dark:hover:bg-red-500/10">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M9 5H5v14h4m5-11 4 4-4 4m-6-4h10" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Sign out
            </button>
        </div>
    </div>

    <dialog x-ref="profileDialog" x-on:close="$refs.accountTrigger.focus()" aria-labelledby="profile-dialog-heading" class="fixed inset-0 m-auto max-h-[85vh] w-[calc(100%-2rem)] max-w-lg overflow-y-auto rounded-2xl border border-slate-200 bg-white p-6 text-slate-900 shadow-2xl backdrop:bg-slate-950/50 backdrop:backdrop-blur-sm sm:p-8 dark:border-slate-700 dark:bg-surface-dark dark:text-slate-100">
        <h2 id="profile-dialog-heading" class="text-xl font-bold">Your profile</h2>
        <dl class="mt-6 flex flex-col gap-4">
            @foreach (['name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'business' => 'Business', 'address' => 'Address'] as $profileField => $profileLabel)
                <div class="border-b border-slate-100 pb-4 dark:border-slate-800">
                    <dt class="text-xs font-medium text-slate-400">{{ $profileLabel }}</dt>
                    <dd class="mt-1.5 whitespace-pre-line break-words text-sm">{{ auth()->user()->{$profileField} ?: 'Not provided' }}</dd>
                </div>
            @endforeach
        </dl>
        <form method="dialog" class="mt-6">
            <button type="submit" autofocus class="w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500">Close profile</button>
        </form>
    </dialog>
</div>
