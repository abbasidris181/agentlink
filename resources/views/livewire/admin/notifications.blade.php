<x-admin-shell :title="ucfirst($channel).' notifications'" section="notifications">
    <div x-data="notificationBatches($wire, {{ $channel === 'whatsapp' ? 5000 : 0 }})" class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-widest text-indigo-500">NOTIFICATION</p>
            <h1 class="mt-2 text-2xl font-bold sm:text-3xl">{{ $channel === 'whatsapp' ? 'WhatsApp' : ucfirst($channel) }} notifications</h1>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Choose recipients, compose your message, and confirm before sending.</p>
        </div>
        @if ($notice)<p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ $notice }}</p>@endif
        @if ($errors->any())
            <div role="alert" class="rounded-xl bg-red-50 p-4 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"><ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        @if ($channel === 'phone')
            @can('notifications.configure')
                <details class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-surface-dark">
                    <summary class="cursor-pointer font-semibold">Parrotica configuration</summary>
                    <form wire:submit="saveProvider" class="mt-5">
                        <fieldset @disabled($locked) x-bind:disabled="busy || {{ $locked ? 'true' : 'false' }}" wire:loading.attr="disabled" class="grid gap-4 disabled:opacity-60 sm:grid-cols-2">
                            <label class="text-sm">HTTPS base URL<input type="url" wire:model="providerUrl" placeholder="https://api.parrotica.com" required class="mt-1 w-full rounded-xl border border-slate-200 bg-transparent p-3 dark:border-slate-700"></label>
                            <label class="text-sm">User ID<input wire:model="providerUserId" required maxlength="255" class="mt-1 w-full rounded-xl border border-slate-200 bg-transparent p-3 dark:border-slate-700"></label>
                            <label class="text-sm">Approved sender ID<input wire:model="providerSenderId" required maxlength="16" class="mt-1 w-full rounded-xl border border-slate-200 bg-transparent p-3 dark:border-slate-700"><span class="mt-1 block text-xs text-slate-500">Up to 11 letters, digits or spaces, or 16 digits.</span></label>
                            <label class="text-sm">API key<input type="password" wire:model="providerApiKey" autocomplete="new-password" placeholder="{{ $apiKeyConfigured ? 'Configured — leave blank to keep' : 'Enter API key' }}" class="mt-1 w-full rounded-xl border border-slate-200 bg-transparent p-3 dark:border-slate-700"></label>
                            <div class="sm:col-span-2"><button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white">Save configuration</button></div>
                        </fieldset>
                    </form>
                </details>
            @endcan
        @endif

        <section aria-label="Recipient selection" class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-surface-dark">
            <fieldset @disabled($locked) x-bind:disabled="busy || {{ $locked ? 'true' : 'false' }}" wire:loading.attr="disabled" class="disabled:opacity-60">
                <div class="space-y-4 p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold">Recipients</h2>
                        <p class="text-sm tabular-nums"><strong>{{ number_format($selectedCount) }}</strong> selected users <span aria-hidden="true">·</span> <strong>{{ number_format($uniqueCount) }}</strong> unique contacts</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-[1fr_12rem]">
                        <label><span class="sr-only">Search users</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name, username, email or phone" maxlength="255" class="w-full rounded-xl border border-slate-200 bg-transparent px-4 py-3 text-sm dark:border-slate-700"></label>
                        <label><span class="sr-only">Users per page</span><select wire:model.live="perPage" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-900">@foreach ([100, 200, 500] as $size)<option value="{{ $size }}">{{ $size }} users per page</option>@endforeach</select></label>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <button type="button" wire:click="selectPage" class="rounded-lg bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-200">Select eligible users on this page</button>
                        <button type="button" wire:click="clearSelection" class="rounded-lg border border-slate-200 px-3 py-2 text-sm dark:border-slate-700">Clear selection</button>
                    </div>
                    <p class="text-xs leading-5 text-slate-500 dark:text-slate-400">Earliest joined first; unknown dates last. Inactive accounts are included. Missing or invalid contacts cannot be selected. Shared contacts receive one message per operation. Username shows — where no username is recorded.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-3xl text-left text-sm">
                        <caption class="sr-only">Notification recipient directory</caption>
                        <thead class="bg-slate-50 text-slate-500 dark:bg-slate-800"><tr>@foreach (['Select', 'Name', 'Username', $channel === 'email' ? 'Email' : 'Phone', 'Joined date', 'Account status'] as $heading)<th scope="col" class="px-5 py-3">{{ $heading }}</th>@endforeach</tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @forelse ($users as $user)
                                @php($contact = \App\NotificationContact::forUser($user, $channel))
                                <tr wire:key="recipient-{{ $channel }}-{{ $user->id }}">
                                    <td class="px-5 py-3"><input type="checkbox" wire:model.live="selected" value="{{ $user->id }}" @disabled($contact === null) aria-label="Select {{ $user->name }}" class="size-4 accent-indigo-600 disabled:cursor-not-allowed"></td>
                                    <td class="px-5 py-3 font-medium">{{ $user->name ?: trim($user->first_name.' '.$user->surname) ?: 'Not recorded' }}</td>
                                    <td class="px-5 py-3">{{ $user->getRawOriginal('username') ?: '—' }}</td>
                                    <td class="px-5 py-3"><span class="break-all">{{ $contact ?? ($channel === 'email' ? $user->email : $user->phone) ?: 'Missing contact' }}</span>@if ($contact === null)<span class="mt-1 block text-xs text-red-600 dark:text-red-400">Not eligible</span>@endif</td>
                                    <td class="whitespace-nowrap px-5 py-3 text-xs">{{ $user->created_at?->format('d M Y H:i') ?? 'Unknown' }}</td>
                                    <td class="px-5 py-3"><span @class(['rounded-full px-2.5 py-1 text-xs font-semibold', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $user->is_active, 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300' => ! $user->is_active])>{{ $user->is_active ? 'Active' : 'Inactive' }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-5 py-10 text-center text-slate-500">No users match your search.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="p-5">{{ $users->links() }}</div>
            </fieldset>
        </section>

        <section aria-label="Compose notification" class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-surface-dark">
            <h2 class="text-lg font-semibold">Compose message</h2>
            <form wire:submit="prepare" class="mt-5">
                <fieldset @disabled($locked) x-bind:disabled="busy || {{ $locked ? 'true' : 'false' }}" wire:loading.attr="disabled" class="space-y-4 disabled:opacity-60">
                    <label class="block text-sm">Provider<select disabled class="mt-1 w-full rounded-xl border border-slate-200 bg-transparent p-3 dark:border-slate-700"><option>{{ match ($channel) { 'email' => 'Hosting email', 'phone' => 'Parrotica', default => 'WhatsApp' } }}</option><option disabled>Second provider — coming later</option></select></label>
                    @if ($channel === 'email')
                        <label class="block text-sm">Subject<input wire:model="subject" required minlength="1" maxlength="200" class="mt-1 w-full rounded-xl border border-slate-200 bg-transparent p-3 dark:border-slate-700"></label>
                    @endif
                    <label class="block text-sm">Plain-text message<textarea wire:model="message" required rows="6" @if ($channel === 'phone') maxlength="905" @endif class="mt-1 w-full rounded-xl border border-slate-200 bg-transparent p-3 dark:border-slate-700"></textarea></label>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ match ($channel) { 'email' => 'Maximum 65,535 bytes. Each recipient receives a separate email using this website’s configured mail sender.', 'phone' => 'Maximum 905 characters. Parrotica acceptance means accepted/queued, not final delivery.', default => 'Maximum 65,535 bytes. WhatsApp messages are sent individually, one contact per request. Acceptance does not confirm delivery.' } }}</p>
                    @if (($totals['uncertain'] ?? 0) > 0)
                        <label class="flex items-start gap-2 text-sm text-amber-700 dark:text-amber-300"><input type="checkbox" wire:model="reviewedUncertain" class="mt-1">I checked the provider before selecting uncertain contacts for a new send.</label>
                    @endif
                    @can('notifications.send')
                        <button type="submit" class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed">Review send</button>
                    @else
                        <p class="text-sm text-slate-500">Your staff role can view recipients but does not have permission to send notifications.</p>
                    @endcan
                </fieldset>
            </form>
        </section>

        @if ($send?->status === 'draft')
            <section role="region" aria-label="Confirm notification" class="rounded-2xl border border-indigo-300 bg-indigo-50 p-5 dark:border-indigo-700 dark:bg-indigo-950">
                <h2 class="font-semibold">Confirm {{ match ($channel) { 'email' => 'email', 'phone' => 'SMS', default => 'WhatsApp' } }} notification</h2>
                <p class="mt-2 text-sm">Send to <strong>{{ number_format($totals['total']) }} unique {{ $channel === 'email' ? 'email addresses' : 'phone numbers' }}</strong>?</p>
                @if ($channel === 'email')<p class="mt-3 break-words text-sm font-semibold">{{ $send->payload['subject'] }}</p>@endif
                <p class="mt-3 max-h-40 overflow-auto whitespace-pre-wrap break-words text-sm">{{ $send->payload['message'] }}</p>
                <div class="mt-5 flex gap-3">
                    <button type="button" x-on:click="start()" x-bind:disabled="busy" wire:loading.attr="disabled" class="rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">Confirm and send</button>
                    <button type="button" wire:click="cancelConfirmation" x-bind:disabled="busy" wire:loading.attr="disabled" class="rounded-xl border border-indigo-300 px-4 py-3 text-sm disabled:opacity-50">Cancel</button>
                </div>
            </section>
        @endif

        <div x-cloak x-show="interrupted" role="alert" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">The request was interrupted. The outcome may be uncertain. Sending has stopped. Check the provider before resending; reload this page to reconcile recorded results.</div>
        @if ($send && $send->status !== 'draft')
            <section aria-label="Send results" aria-live="polite" class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-surface-dark">
                <div class="flex flex-wrap justify-between gap-3"><h2 class="font-semibold">Send results</h2><span class="text-sm" x-text="busy ? 'Sending batches…' : '{{ ucfirst($send->status) }}'"></span></div>
                @php($done = $totals['accepted'] + $totals['failed'] + $totals['uncertain'])
                <p class="mt-3 text-sm">{{ number_format($done) }} of {{ number_format($totals['total']) }} contacts processed · Batch size up to {{ $batchSize }}</p>
                <progress class="mt-3 h-2 w-full accent-indigo-600" max="{{ max(1, $totals['total']) }}" value="{{ $done }}">{{ $done }}</progress>
                <dl class="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    @foreach (['accepted' => 'Accepted / queued', 'failed' => 'Failed', 'uncertain' => 'Uncertain', 'not_attempted' => 'Not attempted'] as $key => $label)
                        <div><dt class="text-xs text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($totals[$key]) }}</dd></div>
                    @endforeach
                </dl>
                @if ($send->status === 'stopped')
                    <p class="mt-5 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">Sending stopped. Check the provider before resending uncertain contacts. No uncertain sends will be retried automatically.</p>
                @endif
                @if ($channel !== 'email')<p class="mt-4 text-xs text-slate-500">These results describe provider acceptance, not final {{ $channel === 'phone' ? 'SMS' : 'WhatsApp' }} delivery.</p>@endif
            </section>
        @endif
    </div>
</x-admin-shell>
