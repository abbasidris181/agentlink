<div>
    <h1 id="verification-heading" class="text-3xl font-bold tracking-tight">Verify your email</h1>
    <p class="mt-3 text-sm leading-6 text-slate-500 dark:text-muted-light">We sent a verification link to <span class="break-all font-semibold text-slate-700 dark:text-slate-200">{{ auth()->user()->email }}</span>. Open it to activate access to your dashboard and services.</p>
    @if (session('email_delivery_failed'))<p role="alert" class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">Your account was created, but we could not send the verification email. Try resending or contact support.</p>@endif
    @if (session('status'))<p role="status" class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200">{{ session('status') }}</p>@endif
    <p class="mt-5 text-xs leading-5 text-slate-500 dark:text-muted-light">Check your spam folder too. Verification links expire after 60 minutes.</p>
    @error('verification')<p role="alert" class="mt-4 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
    <button type="button" wire:click="resend" wire:loading.attr="disabled" class="mt-6 w-full rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white disabled:opacity-50">Resend verification email</button>
    <a href="{{ route('dashboard') }}" class="mt-4 block text-center text-sm font-semibold text-indigo-600 dark:text-indigo-400">I have verified my email</a>
    <button type="button" wire:click="logout" class="mt-6 w-full rounded-xl border border-slate-200 px-5 py-3 text-sm font-semibold dark:border-slate-700">Sign out</button>
</div>
