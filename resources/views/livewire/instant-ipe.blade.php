<div class="mx-auto max-w-6xl">
    <x-service-availability :available="$canSubmit">
    @include('livewire.service-unavailable')
    <a href="{{ route('dashboard') }}" class="mb-6 inline-flex items-center gap-2 text-sm font-medium text-slate-500 transition hover:text-indigo-600 dark:text-muted-light dark:hover:text-indigo-300">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Back to dashboard
    </a>
    <div class="page-banner mb-7 flex items-center gap-4">
        <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300">
            @include('livewire.service-icon', ['serviceId' => 'instant-ipe', 'iconClass' => 'size-5', 'imageClass' => 'size-12'])
        </span>
        <div>
            <p class="mb-1 text-xs font-semibold uppercase tracking-widest text-indigo-600 dark:text-indigo-400">IPE services</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Instant IPE</h1>
        </div>
    </div>
    <section aria-labelledby="instant-ipe-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-200/60 dark:border-slate-800 dark:bg-surface-dark dark:shadow-black/30">
        <div class="border-b border-slate-100 px-6 py-5 sm:px-8 dark:border-slate-800">
            <h2 id="instant-ipe-heading" class="text-xl font-semibold">Request details</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500 dark:text-muted-light">Submit one tracking ID or paste a list using bulk input.</p>
        </div>
        @include('livewire.service-price', ['price' => $price, 'perTrackingId' => true])
        <p class="px-6 pt-4 text-sm text-slate-500 sm:px-8 dark:text-muted-light">The configured price is charged per tracking ID when you submit. Confirmed provider failures are refunded automatically.</p>
        <form wire:submit="submit" class="flex flex-col gap-6 p-6 sm:p-8">
            <div>
                <label for="instant-ipe-mode" class="mb-2 block text-sm font-medium">Input option</label>
                <select wire:model.live="mode" id="instant-ipe-mode" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800">
                    <option value="single">Single tracking ID</option>
                    <option value="bulk">Bulk input</option>
                </select>
            </div>
            <div x-data wire:key="instant-ipe-input-{{ $mode }}">
                <label for="instant-ipe-tracking" class="mb-2 block text-sm font-medium">{{ $mode === 'bulk' ? 'Tracking IDs' : 'Tracking ID' }}</label>
                @if ($mode === 'bulk')
                    <textarea x-bind:value="$wire.trackingInput" x-on:input="$wire.trackingInput = window.formatInstantIpeField($el, true)" id="instant-ipe-tracking" name="trackingInput" rows="8" required spellcheck="false" autocomplete="off" aria-describedby="instant-ipe-help instant-ipe-errors" @if ($errors->any()) aria-invalid="true" @endif placeholder="ABC123DEF456GH7&#10;XYZ987UVW654ST3" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800 resize-y font-mono"></textarea>
                @else
                    <input x-bind:value="$wire.trackingInput" x-on:input="$wire.trackingInput = window.formatInstantIpeField($el, false)" id="instant-ipe-tracking" name="trackingInput" type="text" required minlength="15" maxlength="15" pattern="[A-Za-z0-9]{15}" spellcheck="false" autocomplete="off" aria-describedby="instant-ipe-help instant-ipe-errors" @if ($errors->any()) aria-invalid="true" @endif placeholder="Enter your 15-character tracking ID" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800 font-mono">
                @endif
                <p id="instant-ipe-help" class="mt-2 text-xs leading-5 text-slate-400">Each tracking ID must contain 15 letters or digits. IDs are formatted to uppercase. In bulk mode, paste or type IDs separated by new lines, spaces, commas, or semicolons. Two or more IDs receive an 8-digit batch ID.</p>
                @if ($errors->any())
                    <ul id="instant-ipe-errors" role="alert" class="mt-2 flex flex-col gap-1 text-xs text-red-600 dark:text-red-400">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <div class="border-t border-slate-100 pt-6 dark:border-slate-800">
                <button type="submit" @disabled(! $canSubmit) wire:loading.attr="disabled" wire:target="submit,mode" class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/20 transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-500 disabled:cursor-wait disabled:opacity-60 sm:w-auto">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m12 3 8 4v5c0 4-4 7-8 9-4-2-8-5-8-9V7l8-4Z" stroke-linejoin="round"/><path d="m8 12 3 3 5-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span wire:loading.remove wire:target="submit">Submit request</span>
                    <span wire:loading wire:target="submit">Submitting...</span>
                </button>
            </div>
            @if ($showAvailability)
                <div x-data x-show="$wire.trackingInput === $wire.preparedInput" role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">
                    <p class="font-semibold">{{ count($trackingIds) }} {{ count($trackingIds) === 1 ? 'tracking ID' : 'tracking IDs' }} submitted.</p>
                    @if ($batchId !== null)
                        <p>Batch ID: <button type="button" wire:click="viewBatch('{{ $batchId }}')" aria-label="View batch {{ $batchId }}" class="font-mono font-semibold underline underline-offset-2">{{ $batchId }}</button></p>
                    @endif
                    <p class="mt-1">Wallet charged: {{ \App\Money::format($totalCharged) }}. Requests are submitted for processing.</p>
                </div>
            @endif
        </form>
    </section>
    <section wire:poll.15s.visible aria-labelledby="instant-ipe-history" class="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-200/60 dark:border-slate-800 dark:bg-surface-dark dark:shadow-black/30">
        <div class="border-b border-slate-100 px-6 py-5 sm:px-8 dark:border-slate-800">
            <h2 id="instant-ipe-history" class="text-xl font-semibold">Instant IPE history</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500 dark:text-muted-light">Your tracking ID transactions. </p>
            @error('statusCheck')<p role="alert" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-6xl table-fixed text-left text-sm">
                <caption class="sr-only">Instant IPE transaction history</caption>
                <thead class="border-b border-slate-100 bg-slate-50 text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-800/60 dark:text-muted-light">
                    <tr>
                        <th scope="col" class="w-[14%] px-4 py-4 font-semibold">Transaction ID</th>
                        <th scope="col" class="w-[16%] px-4 py-4 font-semibold">Tracking ID</th>
                        <th scope="col" class="w-[10%] px-4 py-4 font-semibold">Batch ID</th>
                        <th scope="col" class="w-[10%] px-4 py-4 font-semibold">Action</th>
                        <th scope="col" class="w-[24%] px-4 py-4 font-semibold">Reply</th>
                        <th scope="col" class="w-[8%] px-4 py-4 font-semibold">Price</th>
                        <th scope="col" class="w-[8%] px-4 py-4 font-semibold">Status</th>
                        <th scope="col" class="w-[10%] px-4 py-4 font-semibold">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($history as $request)
                        <tr wire:key="instant-ipe-{{ $request->id }}" class="border-b border-slate-100 align-top dark:border-slate-800">
                            <td class="break-all px-4 py-4 font-mono text-xs">{{ $request->reference }}</td>
                            <td class="break-all px-4 py-4 font-mono text-sm">{{ $request->tracking_id }}</td>
                            <td class="px-4 py-4 font-mono text-xs">
                                @if ($request->submission->batch_id !== null)
                                    <button type="button" wire:click="viewBatch('{{ $request->submission->batch_id }}')" aria-label="View batch {{ $request->submission->batch_id }}" class="font-semibold text-indigo-600 underline underline-offset-2 hover:text-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-4 dark:text-indigo-300">{{ $request->submission->batch_id }}</button>
                                @else
                                    <span>-</span>
                                @endif
                            </td>
                            <td class="px-4 py-4"><button type="button" wire:click="checkStatus({{ $request->id }})" wire:loading.attr="disabled" wire:target="checkStatus" @disabled(! $request->canCheck()) class="rounded-lg bg-indigo-50 px-2 py-2 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-indigo-950 dark:text-indigo-300">Check status</button></td>
                            <td class="break-words px-4 py-4">
                                @if ($request->reply !== null && $request->reply !== '')<p class="whitespace-pre-line">{{ $request->reply }}</p>@endif
                                @if ($request->nin !== null)<p class="mt-2 font-mono text-sm">NIN: {{ $request->nin }}</p>@endif
                                @if (($request->reply === null || $request->reply === '') && $request->nin === null)<span class="text-slate-400">&mdash;</span>@endif
                            </td>
                            <td class="px-4 py-4 tabular-nums">{{ \App\Money::format($request->price) }}</td>
                            <td class="px-4 py-4"><span @class(['inline-block rounded-full px-2 py-1 text-xs font-semibold', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' => $request->status === 'successful', 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300' => $request->status === 'failed', 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300' => ! in_array($request->status, ['successful', 'failed'], true)])>{{ $request->statusLabel() }}</span>
                                @if ($request->refunded_at)<p class="mt-2 text-xs text-emerald-700 dark:text-emerald-300">Refunded {{ \App\Money::format($request->price) }}</p>@endif
                                @if ($request->last_error)<p class="mt-2 text-xs text-slate-500 dark:text-muted-light">{{ $request->last_error }}</p>@endif
                            </td>
                            <td class="px-4 py-4 text-xs"><p>{{ $request->created_at->format('d M Y H:i') }}</p></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-6 py-12 text-center"><p class="font-medium text-slate-600 dark:text-slate-300">No Instant IPE transactions yet</p><p class="mt-2 text-xs leading-5 text-slate-400">Submitted requests and replies will appear here.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-6 py-4">{{ $history->links() }}</div>
    </section>
    @if ($batchReport !== null)
        <dialog wire:key="batch-report-{{ $batchReport['batch_id'] }}" wire:ignore.self x-data="{ copyStatus: '' }" x-init="$el.showModal()" x-on:cancel.prevent="$wire.closeBatch()" aria-labelledby="batch-report-title" class="fixed inset-0 m-auto max-h-[85vh] w-11/12 max-w-4xl overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/60 dark:border-slate-700 dark:bg-surface-dark dark:text-slate-100">
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 px-6 py-5 dark:border-slate-800">
                <div><h2 id="batch-report-title" class="text-xl font-semibold">Batch report <span class="font-mono">{{ $batchReport['batch_id'] }}</span></h2><p class="mt-1 text-xs text-slate-500 dark:text-muted-light">Tracking ID=Reply=NIN. Unavailable reply or NIN values are omitted.</p></div>
                <div class="flex shrink-0 flex-col items-end gap-2">
                    <div class="flex gap-2">
                        <button type="button" x-on:click="copyStatus = await window.copyBatchReport($el.closest('dialog')) ? 'Report copied.' : 'Copy failed. Select the report text to copy it manually.'" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Copy report</button>
                        <button type="button" wire:click="closeBatch" autofocus class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Close</button>
                    </div>
                    <p x-text="copyStatus" role="status" class="max-w-56 text-xs text-slate-500 dark:text-muted-light"></p>
                </div>
            </div>
            <div class="p-6">
                <pre data-report-lines class="max-h-[50vh] overflow-y-auto whitespace-pre-wrap break-all rounded-xl bg-slate-50 p-4 font-mono text-sm leading-7 select-text dark:bg-slate-800">{{ $batchReport['lines'] }}</pre>
                <section aria-labelledby="batch-summary-heading" class="mt-6 border-t border-slate-200 pt-5 dark:border-slate-700">
                    <h3 id="batch-summary-heading" class="text-lg font-semibold">Summary:</h3>
                    <dl data-report-summary class="mt-3 grid gap-2 text-sm tabular-nums">
                        <div class="flex gap-2"><dt>Total Records:</dt><dd class="font-semibold">{{ $batchReport['total'] }}</dd></div>
                        <div class="flex gap-2"><dt>Total Successful:</dt><dd class="font-semibold text-emerald-700 dark:text-emerald-300">{{ $batchReport['successful'] }}</dd></div>
                        <div class="flex gap-2"><dt>Total Failed:</dt><dd class="font-semibold text-red-700 dark:text-red-300">{{ $batchReport['failed'] }}</dd></div>
                        @if ($batchReport['pending'] > 0)<div class="flex gap-2"><dt>Total Pending:</dt><dd class="font-semibold text-amber-700 dark:text-amber-300">{{ $batchReport['pending'] }}</dd></div>@endif
                    </dl>
                </section>
            </div>
        </dialog>
    @endif

    </x-service-availability>
</div>
