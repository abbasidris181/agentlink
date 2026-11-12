<div class="mx-auto max-w-6xl">
    <div class="page-banner mb-7 flex flex-col gap-2">
        <p class="text-xs font-semibold uppercase tracking-widest text-indigo-600 dark:text-indigo-400">Your account</p>
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Transactions</h1>
        <p class="text-sm leading-6 text-slate-500 dark:text-muted-light">Review your service transactions and wallet balance changes.</p>
    </div>
    <section aria-labelledby="transaction-history-heading" class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-surface-dark">
        <div class="border-b border-slate-100 px-6 py-5 sm:px-8 dark:border-slate-800">
            <h2 id="transaction-history-heading" class="text-xl font-semibold">Transaction history</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-4xl text-left text-sm">
                <caption class="sr-only">Your transaction history and wallet balances</caption>
                <thead class="border-b border-slate-100 bg-slate-50 text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-800/60 dark:text-muted-light">
                    <tr>
                        <th scope="col" class="whitespace-nowrap px-6 py-4 font-semibold sm:px-8">Transaction ID</th>
                        <th scope="col" class="px-6 py-4 font-semibold">Service</th>
                        <th scope="col" class="px-6 py-4 font-semibold">Status</th>
                        <th scope="col" class="whitespace-nowrap px-6 py-4 font-semibold">Old balance</th>
                        <th scope="col" class="whitespace-nowrap px-6 py-4 font-semibold">New balance</th>
                        <th scope="col" class="px-6 py-4 font-semibold sm:px-8">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($transactions as $transaction)
                        <tr wire:key="wallet-transaction-{{ $transaction->id }}" class="border-b border-slate-100 dark:border-slate-800">
                            <td class="px-6 py-4 font-mono text-sm sm:px-8">{{ $transaction->reference }}@if ($transaction->transfer_reference)<span class="mt-1 block text-xs text-slate-400">Transfer: {{ $transaction->transfer_reference }}</span>@endif</td>
                            <td class="px-6 py-4">{{ $transaction->service }}</td>
                            <td class="px-6 py-4"><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ ucfirst($transaction->status) }}</span></td>
                            <td class="px-6 py-4 tabular-nums">{{ \App\Money::format($transaction->old_balance) }}</td>
                            <td class="px-6 py-4 tabular-nums">{{ \App\Money::format($transaction->new_balance) }}</td>
                            <td class="whitespace-nowrap px-6 py-4 text-xs text-slate-400 sm:px-8">{{ $transaction->created_at->format('d M Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-14 text-center sm:px-8"><p class="font-medium text-slate-600 dark:text-slate-300">No transactions yet</p><p class="mt-2 text-xs leading-5 text-slate-400">Your service transactions and balance changes will appear here when available.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <div class="mt-5">{{ $transactions->links() }}</div>
</div>
