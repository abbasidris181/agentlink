<div>
    @if (config('site.login_disabled', false))
        <p role="status" class="mb-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">User login is temporarily disabled. Only super administrators can sign in.</p>
    @endif
    <form id="welcome-sign-in" wire:submit="login" x-data="{ showPassword: false }" class="flex flex-col gap-5">
        <div>
            <label for="work-email" class="mb-1.5 block text-xs font-medium text-slate-700 dark:text-slate-300">Email</label>
            <div class="flex items-center rounded-xl bg-slate-50 transition focus-within:bg-white focus-within:ring-2 focus-within:ring-indigo-500 dark:bg-slate-800 dark:focus-within:bg-slate-800">
                <svg class="ml-3.5 size-5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="m3 7 9 6 9-6"/></svg>
                <input id="work-email" name="email" wire:model="email" type="email" autocomplete="username" placeholder="name@enterprise.com" required class="min-w-0 flex-1 bg-transparent px-3 py-3 text-base placeholder:text-slate-400 focus:outline-none">
            </div>
        </div>
        <div>
            <div class="mb-1.5 flex items-center justify-between gap-3">
                <label for="account-password" class="text-xs font-medium text-slate-700 dark:text-slate-300">Password</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-700 dark:text-indigo-400">Forgot password?</a>
                @endif
            </div>
            <div class="flex items-center rounded-xl bg-slate-50 transition focus-within:bg-white focus-within:ring-2 focus-within:ring-indigo-500 dark:bg-slate-800 dark:focus-within:bg-slate-800">
                <svg class="ml-3.5 size-5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/></svg>
                <input id="account-password" name="password" wire:model="password" x-bind:type="showPassword ? 'text' : 'password'" type="password" autocomplete="current-password" placeholder="••••••••••••" required class="min-w-0 flex-1 bg-transparent px-3 py-3 text-base placeholder:text-slate-400 focus:outline-none">
                <button x-on:click="showPassword = !showPassword" x-bind:aria-label="showPassword ? 'Hide password' : 'Show password'" x-bind:aria-pressed="showPassword" type="button" aria-label="Show password" aria-controls="account-password"  class="mr-1 rounded-lg p-2 text-slate-400 transition hover:text-slate-600 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:hover:text-slate-200">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path x-show="!showPassword" d="m3 3 18 18"/></svg>
                </button>
            </div>
        </div>
        <button type="submit" wire:loading.attr="disabled" wire:target="login" class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/20 transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-500 active:scale-[0.99]">
            <span wire:loading.remove wire:target="login">Sign in to Account</span><span wire:loading wire:target="login">Signing in...</span>
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M5 12h14m-6-6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-red-50 p-3 text-xs text-red-600 dark:bg-red-500/10 dark:text-red-400">
                @foreach ($errors->all() as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif
    </form>
</div>
