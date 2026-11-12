<x-admin-shell :title="$tableTitle" section="requests" :request-service="$selectedRequest?->service ?? $tableService">
    @if ($selectedRequest === null)
        <div class="page-banner mb-7"><h1 class="text-3xl font-bold">{{ $tableTitle }}</h1><p class="mt-2 text-sm">Review submitted details, reply to users, and manage refunds.</p></div>
        <div @class(['mb-6 grid gap-3', 'md:grid-cols-3' => $service === null, 'md:grid-cols-2' => $service !== null])>
            <div><label for="request-search" class="mb-2 block text-xs font-semibold">Transaction ID, user name, or email</label><input id="request-search" type="search" wire:model.live.debounce.300ms="search" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-base dark:border-slate-700 dark:bg-surface-dark"></div>
            @if ($service === null)
            <div><label for="request-service" class="mb-2 block text-xs font-semibold">Service</label><select id="request-service" wire:model.live="serviceFilter" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-base dark:border-slate-700 dark:bg-surface-dark"><option value="all">All services</option>@foreach ($services as $key => $definition)<option value="{{ $key }}">{{ $definition['label'] }}</option>@endforeach</select></div>
            @endif
            <div><label for="request-status" class="mb-2 block text-xs font-semibold">Status</label><select id="request-status" wire:model.live="statusFilter" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-base dark:border-slate-700 dark:bg-surface-dark"><option value="all">All statuses</option>@foreach ($statuses as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
        </div>
        <section wire:key="request-copy-{{ $tableService ?? 'all' }}-{{ $requests->currentPage() }}-{{ md5($search.'|'.$statusFilter) }}" x-data="window.serviceRequestCopy()" class="overflow-hidden rounded-3xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-surface-dark">
            <div class="flex flex-col gap-3 border-b border-slate-200 p-5 dark:border-slate-800">
                <p class="text-sm font-semibold">Copy user inputs</p>
                <p class="text-xs text-slate-500 dark:text-muted-light">Tick request rows on this page to copy all their submitted inputs automatically. Copied inputs are uppercase, with a blank line between requests.</p>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" disabled x-bind:disabled="selectedRows.length === 0" x-on:click="copySelected()" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">Copy selected rows</button>
                    <button type="button" x-on:click="selectAll()" class="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-300">Select all rows</button>
                    <button type="button" x-on:click="clearSelection()" class="text-xs font-semibold text-slate-500 hover:underline dark:text-muted-light">Clear selection</button>
                    <span x-cloak x-show="selectedRows.length" x-text="selectedRows.length + ' selected'" class="text-xs text-slate-500 dark:text-muted-light"></span>
                </div>
                <p x-cloak x-show="copyStatus" x-text="copyStatus" role="status" aria-live="polite" class="text-sm font-medium"></p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-7xl text-left text-sm">
                    <caption class="sr-only">Submitted service requests and user inputs</caption>
                    <thead class="bg-slate-50 dark:bg-slate-800"><tr>
                        <th scope="col" class="px-5 py-4 font-semibold">Select</th>
                        @foreach (['Transaction ID', 'User', $tableService === null ? 'Service / method' : 'Method'] as $heading)
                            <th scope="col" class="px-5 py-4 font-semibold">{{ $heading }}</th>
                        @endforeach
                        @if ($inputColumns === [])
                            <th scope="col" class="px-5 py-4 font-semibold">Submitted inputs</th>
                        @else
                            @foreach ($inputColumns as $field => $heading)
                                <th scope="col" class="px-5 py-4 font-semibold">
                                    <span>{{ $heading }}</span>
                                </th>
                            @endforeach
                        @endif
                        @foreach (['Status', 'Reply', 'Price', 'Date', 'Actions'] as $heading)
                            <th scope="col" class="px-5 py-4 font-semibold">{{ $heading }}</th>
                        @endforeach
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($requests as $request)
                            <tr wire:key="admin-request-{{ $request->id }}" data-copy-row="{{ $request->id }}" class="align-top">
                                <td class="px-5 py-4">
                                    <input data-select-row type="checkbox" value="{{ $request->id }}" x-model="selectedRows" x-on:change="$nextTick(() => copySelected())" aria-label="Select request {{ $request->reference }} to copy all inputs" class="size-4 rounded accent-indigo-600">
                                    @if ($request->service === 'bvn-modification')
                                        <span hidden data-copy-field="kind" data-copy-label="Kind" data-copy-value="{{ $request->methodLabel() }}"></span>
                                    @endif
                                    @foreach ($request->inputDetails() as $label => $value)
                                        <span hidden data-copy-field="{{ $loop->index }}" data-copy-label="{{ $label }}" data-copy-value="{{ $value }}"></span>
                                    @endforeach
                                    @if ($request->screenshot_path)
                                        <span hidden data-copy-field="attachment" data-copy-preserve-case="true" data-copy-label="{{ $request->service === 'nin-modification' ? 'Clear photo' : 'Screenshot proof' }}" data-copy-value="{{ route('service-requests.proof', $request) }}"></span>
                                    @endif
                                </td>
                                <td class="px-5 py-4 font-mono">{{ $request->reference }}</td>
                                <td class="px-5 py-4"><p class="font-semibold">{{ $request->user->name }}</p><p class="mt-1 text-xs text-slate-500 dark:text-muted-light">{{ $request->user->email }}</p></td>
                                <td class="px-5 py-4">@if ($tableService === null)<p class="font-semibold">{{ $request->serviceLabel() }}</p>@endif<p class="mt-1 text-xs text-slate-500 dark:text-muted-light">{{ $request->methodLabel() }}</p></td>
                                @if ($inputColumns !== [])
                                    @foreach ($inputColumns as $field => $heading)
                                        <td class="min-w-40 break-all px-5 py-4 font-mono">
                                            @if ($field === 'screenshot_proof')
                                                @if ($request->screenshot_path)
                                                    <a href="{{ route('service-requests.proof', $request) }}" class="font-sans font-semibold text-indigo-600 dark:text-indigo-300">Download screenshot proof</a>
                                                @else
                                                    <span class="text-slate-400">&mdash;</span>
                                                @endif
                                            @else
                                                {{ $request->payload[$field] ?? '?' }}
                                            @endif
                                        </td>
                                    @endforeach
                                @else
                                    <td class="min-w-64 px-5 py-4">
                                        <dl class="grid gap-3">
                                            @foreach ($request->inputDetails() as $label => $value)
                                                <div><dt class="text-xs text-slate-500 dark:text-muted-light">{{ $label }}</dt><dd class="mt-1 break-all font-mono">{{ $value }}</dd></div>
                                            @endforeach
                                        </dl>
                                        @if ($request->screenshot_path)
                                            <a href="{{ route('service-requests.proof', $request) }}" class="mt-3 inline-flex font-semibold text-indigo-600 dark:text-indigo-300">Download screenshot proof</a>
                                        @endif
                                    </td>
                                @endif
                                <td class="px-5 py-4">{{ $request->statusLabel() }}</td>
                                <td class="min-w-80 px-5 py-4">
                                    <p class="whitespace-pre-line break-words">{{ $request->reply ?: 'Awaiting admin reply' }}</p>
                                    @can('requests.manage')
                                        <form wire:submit="saveReply({{ $request->id }}, {{ $request->revision }})" class="mt-3 flex flex-col gap-2">
                                            <label for="request-reply-{{ $request->id }}" class="text-xs font-semibold">Reply to user</label>
                                            <textarea id="request-reply-{{ $request->id }}" wire:model="replies.{{ $request->id }}" rows="3" maxlength="5000" placeholder="Write a reply" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"></textarea>
                                            @error('replies.'.$request->id)<p role="alert" class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                                            <button type="submit" wire:loading.attr="disabled" class="self-start rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">Save reply</button>
                                        </form>
                                    @endcan
                                </td>
                                <td class="px-5 py-4 tabular-nums">{{ $request->quoted_price === null ? 'Not set' : \App\Money::format($request->quoted_price) }}</td>
                                <td class="whitespace-nowrap px-5 py-4 text-xs">{{ $request->created_at->format('d M Y H:i') }}</td>
                                <td class="min-w-44 px-5 py-4">
                                    <a href="{{ route('admin.requests.show', ['requestId' => $request->id]) }}" class="font-semibold text-indigo-600 dark:text-indigo-300">View request</a>
                                    <div class="mt-4">@include('livewire.admin.request-refund', ['request' => $request])</div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ 9 + max(1, count($inputColumns)) }}" class="px-6 py-12 text-center text-slate-500 dark:text-muted-light">No service requests match your filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <div class="mt-5">{{ $requests->links() }}</div>
    @else
        <a href="{{ route('admin.requests.service', ['service' => $selectedRequest->service]) }}" class="mb-5 inline-flex text-sm font-semibold text-indigo-600 dark:text-indigo-300">&larr; {{ $selectedRequest->serviceLabel() }} requests</a>
        <div class="page-banner mb-6"><h1 class="text-2xl font-bold sm:text-3xl">{{ $selectedRequest->serviceLabel() }}</h1><p class="mt-2 break-all font-mono text-sm">{{ $selectedRequest->reference }}</p></div>
        <div class="grid items-start gap-6 xl:grid-cols-2">
            <section class="rounded-3xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-surface-dark"><h2 class="text-xl font-semibold">Submitted details</h2><dl class="mt-5 grid gap-4">
                @foreach (['User' => $selectedRequest->user->name, 'Email' => $selectedRequest->user->email, 'Method' => $selectedRequest->methodLabel(), 'Submitted' => $selectedRequest->created_at->format('d M Y H:i'), 'Status' => $selectedRequest->statusLabel()] as $label => $value)<div><dt class="text-xs text-slate-500 dark:text-muted-light">{{ $label }}</dt><dd class="mt-1 break-words text-sm font-medium">{{ $value }}</dd></div>@endforeach
                @foreach ($selectedRequest->inputDetails() as $label => $value)<div><dt class="text-xs text-slate-500 dark:text-muted-light">{{ $label }}</dt><dd class="mt-1 break-all font-mono text-sm">{{ $value }}</dd></div>@endforeach
                <div><dt class="text-xs text-slate-500 dark:text-muted-light">Price</dt><dd class="mt-1 font-semibold tabular-nums">{{ $selectedRequest->quoted_price === null ? 'Not set' : \App\Money::format($selectedRequest->quoted_price) }}</dd><p class="mt-1 text-xs text-slate-500 dark:text-muted-light">{{ $selectedRequest->walletTransaction?->refund ? 'Payment refunded to wallet.' : ($selectedRequest->walletTransaction ? 'Paid from wallet on submission.' : 'No payment recorded.') }}</p></div>
            </dl>
                <div class="mt-5">@include('livewire.admin.request-refund', ['request' => $selectedRequest])</div>
                @if ($selectedRequest->screenshot_path)<a href="{{ route('service-requests.proof', $selectedRequest) }}" class="mt-6 inline-flex rounded-xl border border-indigo-200 px-4 py-3 text-sm font-semibold text-indigo-600 dark:border-indigo-800 dark:text-indigo-300">Download screenshot proof</a>@endif
            </section>
            <section class="rounded-3xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-surface-dark"><h2 class="text-xl font-semibold">Status and reply</h2>
                @can('requests.manage')
                    <form wire:submit="save" class="mt-5 flex flex-col gap-5"><div><label for="manage-request-status" class="mb-2 block text-sm font-medium">Status</label><select id="manage-request-status" wire:model="status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800">@foreach ($statuses as $key => $label)@if ($key !== 'refunded' || $selectedRequest->status === 'refunded')<option value="{{ $key }}">{{ $label }}</option>@endif @endforeach</select></div><div><label for="manage-request-reply" class="mb-2 block text-sm font-medium">Reply to user</label><textarea id="manage-request-reply" wire:model="reply" rows="8" maxlength="5000" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800"></textarea><p class="mt-2 text-xs text-slate-500 dark:text-muted-light">A reply is required when completing or rejecting a request. The user will see it in their service history. Changing status does not change the original payment.</p></div><button type="submit" wire:loading.attr="disabled" class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">Save status and reply</button></form>
                @else
                    <p class="mt-5 whitespace-pre-line break-words text-sm">{{ $selectedRequest->reply ?: 'Awaiting admin reply' }}</p>
                @endcan
                @if ($selectedRequest->handler)<p class="mt-5 text-xs text-slate-500 dark:text-muted-light">Last updated by {{ $selectedRequest->handler->name }} on {{ $selectedRequest->handled_at->format('d M Y H:i') }}.</p>@endif
            </section>
        </div>
    @endif
</x-admin-shell>
