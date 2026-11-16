@props(['bankName', 'accountNumber', 'accountName'])
@php
    $normalizedBankName = strtolower($bankName);
    $bankLogo = match (true) {
        str_contains($normalizedBankName, 'palmpay') => 'palmpay.png',
        str_contains($normalizedBankName, 'moniepoint') => 'moniepoint microfinance bank.png',
        str_contains($normalizedBankName, 'wema') => 'wema bank.png',
        str_contains($normalizedBankName, 'sterling') => 'Sterling bank.png',
        str_contains($normalizedBankName, 'opay') => 'OPay.jpg',
        default => null,
    };
@endphp
<article {{ $attributes->class(['rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50']) }} x-data="{ copied: false, copyFailed: false }">
    <div class="flex items-center gap-3">
        <div class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-white p-2">
            @if ($bankLogo)
                <img src="{{ asset('assets/images/'.rawurlencode($bankLogo)) }}" alt="{{ $bankName }} logo" width="48" height="48" class="size-full object-contain">
            @else
                <svg class="size-7 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m3 9 9-6 9 6H3Zm2 3v7m5-7v7m4-7v7m5-7v7M3 22h18" stroke-linecap="round" stroke-linejoin="round"/></svg>
            @endif
        </div>
        <h4 class="min-w-0 break-words text-sm font-semibold">{{ $bankName }}</h4>
    </div>
    <dl class="mt-4">
        <div>
            <dt class="text-xs text-slate-500 dark:text-slate-400">Account number</dt>
            <dd class="mt-1 flex flex-wrap items-center justify-between gap-3">
                <span class="select-all text-2xl font-bold tracking-wider tabular-nums">{{ $accountNumber }}</span>
                <button type="button" x-on:click="copyFailed = false; navigator.clipboard?.writeText(@js($accountNumber)).then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => copyFailed = true); if (!navigator.clipboard) copyFailed = true" aria-label="Copy {{ $bankName }} account number" class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
            </dd>
        </div>
        <div class="mt-3">
            <dt class="text-xs text-slate-500 dark:text-slate-400">Account name</dt>
            <dd class="mt-1 break-words text-sm font-medium">{{ $accountName }}</dd>
        </div>
    </dl>
    <p x-cloak x-show="copied" role="status" class="mt-3 text-xs text-emerald-700 dark:text-emerald-300">Account number copied.</p>
    <p x-cloak x-show="copyFailed" role="status" class="mt-3 text-xs text-amber-700 dark:text-amber-300">Select the account number to copy it manually.</p>
</article>
