@if ($request->walletTransaction?->refund)
    <p class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">Refunded ₦{{ \App\Money::format($request->walletTransaction->refund->amount) }}</p>
    <p class="mt-1 break-all font-mono text-xs text-slate-500 dark:text-muted-light">{{ $request->walletTransaction->refund->reference }}</p>
@else
    @can('requests.refund')
        @if ($request->walletTransaction?->amount > 0)
            <button type="button" wire:click="refund({{ $request->id }})" wire:loading.attr="disabled" class="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100 disabled:opacity-50 dark:border-red-900 dark:bg-red-950 dark:text-red-300 dark:hover:bg-red-900">Refund ₦{{ \App\Money::format($request->walletTransaction->amount) }}</button>
        @else
            <span class="text-xs text-slate-500 dark:text-muted-light">No refundable payment</span>
        @endif
    @endcan
@endif
