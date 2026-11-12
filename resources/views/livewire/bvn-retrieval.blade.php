<div class="mx-auto max-w-6xl">
    <x-service-availability :available="$canSubmit">
    @include('livewire.service-unavailable')
    <a href="{{ route('dashboard') }}" class="mb-6 inline-flex items-center gap-2 text-sm font-medium text-slate-500 transition hover:text-indigo-600 dark:text-muted-light dark:hover:text-indigo-300">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Back to dashboard
    </a>
    <div class="page-banner mb-7 flex items-center gap-4">
        <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300">
            @include('livewire.service-icon', ['serviceId' => 'bvn retrival', 'iconClass' => 'size-5', 'imageClass' => 'size-12'])
        </span>
        <div>
            <p class="mb-1 text-xs font-semibold uppercase tracking-widest text-indigo-600 dark:text-indigo-400">BVN services</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">BVN retrieval</h1>
        </div>
    </div>
    <section aria-labelledby="retrieval-form-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-200/60 dark:border-slate-800 dark:bg-surface-dark dark:shadow-black/30">
        <div class="border-b border-slate-100 px-6 py-5 sm:px-8 dark:border-slate-800">
            <h2 id="retrieval-form-heading" class="text-xl font-semibold">Retrieval details</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500 dark:text-muted-light">Choose Phone or CRM, then enter the details for your retrieval request.</p>
        </div>
        @include('livewire.service-price', ['price' => $price])
        <p class="px-6 pt-4 text-sm text-slate-500 sm:px-8 dark:text-muted-light">The configured price is deducted from your wallet when you submit. Your request will then be reviewed by an administrator.</p>
        <form wire:submit="submit" class="flex flex-col gap-6 p-6 sm:p-8">
            <div>
                <label for="retrieval-method" class="mb-2 block text-sm font-medium">Retrieval option</label>
                <select wire:model.live="method" id="retrieval-method" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800">
                    <option value="phone">Phone</option>
                    <option value="crm">CRM</option>
                </select>
                @error('method') <p role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            @if ($method === 'phone')
                <div wire:key="phone-retrieval" class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="retrieval-phone" class="mb-2 block text-sm font-medium">Registered phone number</label>
                        <input wire:model="phone" id="retrieval-phone" name="phone" type="tel" autocomplete="tel" placeholder="08012345678 or +2348012345678" required maxlength="25" aria-describedby="phone-error" @error('phone') aria-invalid="true" @enderror class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800">
                        @error('phone') <p id="phone-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="retrieval-fullName" class="mb-2 block text-sm font-medium">Full name (as on record)</label>
                        <input wire:model="fullName" id="retrieval-fullName" name="fullName" type="text" autocomplete="name" placeholder="Enter your full name as on record" required maxlength="200" aria-describedby="fullName-error" @error('fullName') aria-invalid="true" @enderror class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800">
                        @error('fullName') <p id="fullName-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>
            @elseif ($method === 'crm')
                <div wire:key="crm-retrieval" class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="retrieval-agentCode" class="mb-2 block text-sm font-medium">Agent Code</label>
                        <input wire:model="agentCode" id="retrieval-agentCode" name="agentCode" type="text" autocomplete="off" required maxlength="100" aria-describedby="agentCode-error" @error('agentCode') aria-invalid="true" @enderror class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800 font-mono">
                        @error('agentCode') <p id="agentCode-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="retrieval-ticketId" class="mb-2 block text-sm font-medium">Ticket ID</label>
                        <input wire:model="ticketId" id="retrieval-ticketId" name="ticketId" type="text" autocomplete="off" required maxlength="100" aria-describedby="ticketId-error" @error('ticketId') aria-invalid="true" @enderror class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800 font-mono">
                        @error('ticketId') <p id="ticketId-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="retrieval-bmsTicket" class="mb-2 block text-sm font-medium">BMS Ticket</label>
                        <input wire:model="bmsTicket" id="retrieval-bmsTicket" name="bmsTicket" type="text" autocomplete="off" required maxlength="100" aria-describedby="bmsTicket-error" @error('bmsTicket') aria-invalid="true" @enderror class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800 font-mono">
                        @error('bmsTicket') <p id="bmsTicket-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="retrieval-proof" class="mb-2 block text-sm font-medium">Upload screenshot proof</label>
                        <input wire:model="screenshotProof" id="retrieval-proof" name="screenshotProof" type="file" accept="image/jpeg,image/png,image/webp" required aria-describedby="proof-help proof-error" @error('screenshotProof') aria-invalid="true" @enderror class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-100 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-indigo-700 dark:file:bg-indigo-500/15 dark:file:text-indigo-300">
                        <p id="proof-help" class="mt-2 text-xs text-slate-400">JPG, PNG, or WebP. Maximum 5 MB.</p>
                        <p wire:loading wire:target="screenshotProof" role="status" class="mt-2 text-xs text-indigo-600 dark:text-indigo-300">Uploading screenshot...</p>
                        @if ($screenshotProof)
                            <p class="mt-2 break-all text-xs text-slate-500 dark:text-muted-light">Selected: {{ $screenshotProof->getClientOriginalName() }}</p>
                        @endif
                        @error('screenshotProof') <p id="proof-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif
            <div class="border-t border-slate-100 pt-6 dark:border-slate-800">
                <button type="submit" @disabled(! $canSubmit) wire:loading.attr="disabled" wire:target="submit,screenshotProof,method" class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/20 transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-500 disabled:cursor-wait disabled:opacity-60 sm:w-auto">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m12 3 8 4v5c0 4-4 7-8 9-4-2-8-5-8-9V7l8-4Z" stroke-linejoin="round"/><path d="m8 12 3 3 5-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span wire:loading.remove wire:target="submit">Submit request</span>
                    <span wire:loading wire:target="submit">Submitting...</span>
                </button>
            </div>
            @if ($showAvailability)
                <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">Request submitted. Reference: <span class="break-all font-mono">{{ $submittedReference }}</span>. Wallet charged: {{ \App\Money::format($submittedPriceMinor) }}.</p>
            @endif
        </form>
    </section>
    <section aria-labelledby="retrieval-history-heading" class="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-200/60 dark:border-slate-800 dark:bg-surface-dark dark:shadow-black/30">
        <div class="border-b border-slate-100 px-6 py-5 sm:px-8 dark:border-slate-800">
            <h2 id="retrieval-history-heading" class="text-xl font-semibold">Retrieval history</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500 dark:text-muted-light">Your BVN retrieval transactions and reply.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-4xl table-fixed text-left text-sm">
                <caption class="sr-only">BVN retrieval request history</caption>
                <thead class="border-b border-slate-100 bg-slate-50 text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-800/60 dark:text-muted-light">
                    <tr>
                        <th scope="col" class="w-[16%] px-6 py-4 font-semibold">Transaction id</th>
                        <th scope="col" class="w-[12%] px-6 py-4 font-semibold">Date</th>
                        <th scope="col" class="w-[24%] px-6 py-4 font-semibold">Input field</th>
                        <th scope="col" class="w-[24%] px-6 py-4 font-semibold">Reply</th>
                        <th scope="col" class="w-[12%] px-6 py-4 font-semibold">Price</th>
                        <th scope="col" class="w-[12%] px-6 py-4 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($history as $request)
                        <tr wire:key="request-{{ $request->id }}" class="border-b border-slate-100 dark:border-slate-800"><td class="break-all px-6 py-4 font-mono text-sm">{{ $request->reference }}</td><td class="px-6 py-4 text-xs text-slate-500 dark:text-muted-light">{{ $request->created_at->format('d M Y H:i') }}</td><td class="break-words px-6 py-4 font-mono text-sm">{{ $request->inputSummary() }}</td><td class="whitespace-pre-line break-words px-6 py-4">{{ $request->reply ?: 'Awaiting admin reply' }}</td><td class="px-6 py-4 tabular-nums">{{ $request->quoted_price === null ? 'Not set' : \App\Money::format($request->quoted_price) }}</td><td class="px-6 py-4"><span @class(['inline-block rounded-full px-2 py-1 text-xs font-semibold', 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' => $request->status === 'completed', 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300' => $request->status === 'rejected', 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300' => in_array($request->status, ['pending', 'in_progress'], true)])>{{ $request->statusLabel() }}</span></td></tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            <p class="font-medium text-slate-600 dark:text-slate-300">No requests yet</p>
                            <p class="mt-2 text-xs leading-5 text-slate-400">BVN retrieval transactions and replies will appear here after a request is submitted.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    </x-service-availability>
</div>
