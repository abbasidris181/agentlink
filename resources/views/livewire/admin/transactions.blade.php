<x-admin-shell :title="$title" section="transactions" :transaction-group="$transactionGroup">
    <div class="page-banner mb-7">
        <p class="text-xs font-semibold uppercase tracking-widest text-indigo-600 dark:text-indigo-400">Transactions</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight">{{ $title }}</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-muted-light">Wallet debits, credits, and refunds across customer accounts.</p>
    </div>
    <section wire:poll.15s.visible class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-surface-dark">
        <div class="flex items-center justify-between gap-4 border-b border-slate-100 px-6 py-5 dark:border-slate-800">
            <h2 class="text-xl font-semibold">Transaction history</h2>
            <span class="text-sm tabular-nums text-slate-500 dark:text-muted-light">{{ number_format($transactions->total()) }} records</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-5xl text-left text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                    <tr>@foreach (array_merge(['Transaction ID', 'User', 'Service'], $transactionGroup === 'instant-ipe' ? ['Tracking ID', 'Batch ID', 'Request status', 'Reply', 'Action'] : [], ['Type', 'Amount', 'Status', 'Old balance', 'New balance', 'Date']) as $heading)<th scope="col" class="whitespace-nowrap px-4 py-4 font-semibold">{{ $heading }}</th>@endforeach</tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($transactions as $transaction)
                        <tr wire:key="admin-transaction-{{ $transaction->id }}" class="align-top">
                            <td class="break-all px-4 py-4 font-mono text-xs">{{ $transaction->reference }}</td>
                            <td class="px-4 py-4"><p class="font-medium">{{ $transaction->user?->name ?? 'Unknown user' }}</p>@can('users.contact')<p class="mt-1 text-xs text-slate-500 dark:text-muted-light">{{ $transaction->user?->email }}</p>@endcan</td>
                            <td class="px-4 py-4">{{ $transaction->service }}</td>
                            @if ($transactionGroup === 'instant-ipe')
                                @php($request = $transaction->instantIpeRequest)
                                <td class="px-4 py-4 font-mono text-xs">{{ $request?->tracking_id ?? '?' }}</td>
                                <td class="px-4 py-4 font-mono text-xs">{{ $request?->submission?->batch_id ?? '?' }}</td>
                                <td class="px-4 py-4">{{ $request?->refunded_at ? 'Refunded' : ($request?->status === 'successful' ? 'Completed' : ($request?->statusLabel() ?? '?')) }}</td>
                                <td class="min-w-72 px-4 py-4">
                                    @if ($request)
                                        @if ($request->reply !== null)<p class="whitespace-pre-wrap break-words">{{ $request->reply }}</p>@endif
                                        @if ($request->nin !== null)<p class="mt-2 font-mono text-xs">NIN: {{ $request->nin }}</p>@endif
                                        @if ($request->reply === null && $request->nin === null)<span class="text-slate-400">?</span>@endif
                                        @can('requests.manage')
                                            @if ($replyRequestId === $request->id)
                                                <form wire:submit="saveReply" class="mt-3 flex flex-col gap-2">
                                                    <label for="admin-ipe-reply-{{ $request->id }}" class="text-xs font-semibold">Reply to user</label>
                                                    <textarea placeholder="Enter the result or reply for this request" wire:model="reply" id="admin-ipe-reply-{{ $request->id }}" rows="3" required maxlength="10000" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
                                                    <div class="flex gap-2">
                                                        <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Send reply</button>
                                                        <button type="button" wire:click="cancelReply" class="rounded-lg border border-slate-200 px-3 py-2 text-xs dark:border-slate-700">Cancel</button>
                                                    </div>
                                                </form>
                                            @endif
                                        @endcan
                                    @else
                                        <span class="text-slate-400">?</span>
                                    @endif
                                </td>
                                <td class="px-4 py-4">
                                    @if ($request)
                                        <div class="flex min-w-28 flex-col items-start gap-2">
                                            @can('requests.refund')
                                                <button type="button" wire:click="refund({{ $request->id }})" wire:loading.attr="disabled" @disabled($request->refunded_at !== null || $transaction->refund !== null || $transaction->amount < 1) class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 disabled:cursor-not-allowed disabled:opacity-40 dark:border-red-900 dark:bg-red-950 dark:text-red-300">Refund</button>
                                            @endcan
                                            @can('requests.manage')
                                                <button type="button" wire:click="editReply({{ $request->id }})" wire:loading.attr="disabled" class="rounded-lg bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-700 disabled:opacity-40 dark:bg-indigo-950 dark:text-indigo-300">Reply</button>
                                                <button type="button" wire:click="complete({{ $request->id }})" wire:loading.attr="disabled" @disabled(in_array($request->status, ['successful', 'failed'], true) || $request->refunded_at !== null) class="rounded-lg bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-emerald-950 dark:text-emerald-300">Complete</button>
                                            @endcan
                                        </div>
                                    @else
                                        <span class="text-slate-400">?</span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-4 py-4">{{ ucfirst(str_replace('_', ' ', $transaction->type)) }}</td>
                            <td class="px-4 py-4 tabular-nums">{{ \App\Money::format($transaction->amount) }}</td>
                            <td class="px-4 py-4"><span class="inline-block whitespace-nowrap rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold dark:bg-slate-800">{{ ucfirst($transaction->status) }}</span></td>
                            <td class="px-4 py-4 tabular-nums">{{ \App\Money::format($transaction->old_balance) }}</td>
                            <td class="px-4 py-4 tabular-nums">{{ \App\Money::format($transaction->new_balance) }}</td>
                            <td class="whitespace-nowrap px-4 py-4 text-xs">{{ $transaction->created_at->format('d M Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $transactionGroup === 'instant-ipe' ? 14 : 9 }}" class="px-6 py-12 text-center text-sm text-slate-500 dark:text-muted-light">No transactions found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-6 py-4">{{ $transactions->links() }}</div>
    </section>
</x-admin-shell>
