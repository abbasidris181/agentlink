<section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark">
    <h2 class="text-xl font-semibold">Monielinks wallet funding</h2>
    <p class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">Create PalmPay virtual accounts using customers’ registered names and email addresses.</p>
    @if ($notice)<p role="status" class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ $notice }}</p>@endif
    @if ($errors->any())<div role="alert" class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm leading-6 text-amber-900 dark:bg-amber-950 dark:text-amber-200">Payments are matched by the full virtual account number. Monielinks currently sends unsigned notifications, which are saved as unverified for review. Automatic wallet crediting needs an authenticated transaction-verification endpoint or confirmed webhook signing.</p>
    <form wire:submit="save" class="mt-5">
        <fieldset @cannot('settings.edit') disabled @endcannot wire:loading.attr="disabled" class="grid gap-5 disabled:opacity-60 sm:grid-cols-2">
            <label class="flex items-center gap-3 text-sm font-medium sm:col-span-2"><input type="checkbox" wire:model="enabled" class="size-5 accent-indigo-600">Allow new Monielinks accounts</label>
            <label class="text-sm font-medium">Client ID<input type="password" wire:model="clientId" autocomplete="new-password" placeholder="{{ $clientIdConfigured ? 'Configured — leave blank to keep' : 'Enter Monielinks client ID' }}" class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800"></label>
            <label class="text-sm font-medium">Secret key (secret_id)<input type="password" wire:model="secretKey" autocomplete="new-password" placeholder="{{ $secretKeyConfigured ? 'Configured — leave blank to keep' : 'Enter Monielinks secret key' }}" class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800"></label>
            <label class="text-sm font-medium">Webhook signing secret (optional)<input type="password" wire:model="webhookSecret" autocomplete="new-password" placeholder="{{ $webhookSecretConfigured ? 'Configured — leave blank to keep' : 'Leave empty while signing is disabled' }}" class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800"><span class="mt-2 block text-xs font-normal leading-5 text-slate-500 dark:text-slate-400">Leave empty for the current unsigned webhook. If Monielinks later confirms hexadecimal HMAC-SHA256 of the raw JSON body in X-Signature, enter its signing secret here. Once configured, missing or invalid signatures are rejected.</span></label>
            <label class="text-sm font-medium">Funding charge (%)<input type="number" wire:model="fundingCharge" placeholder="e.g. 2.00" required min="0" max="100" step="0.01" class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800"><span class="mt-2 block text-xs font-normal leading-5 text-slate-500 dark:text-slate-400">Saved for wallet funding when payment confirmation is connected. For example, 2% of ₦5,000 deducts ₦100 and credits ₦4,900.</span></label>
            <div class="text-sm sm:col-span-2"><label for="monielinks-webhook" class="font-medium">Payment webhook URL</label><input id="monielinks-webhook" readonly value="{{ route('monielinks.webhook') }}" class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs dark:border-slate-700 dark:bg-slate-800"><p class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">Configure this URL in Monielinks. API credentials are saved in the database. Existing values stay hidden.</p></div>
            @can('settings.edit')<div class="sm:col-span-2"><button type="submit" class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white">Save Monielinks settings</button></div>@endcan
        </fieldset>
    </form>
    @if ($recentPayments->isNotEmpty())
        <div class="mt-6 overflow-x-auto">
            <h3 class="mb-3 text-sm font-semibold">Recent Monielinks payment notifications</h3>
            <table class="w-full text-left text-xs">
                <thead class="text-slate-500 dark:text-slate-400"><tr><th class="p-2">Transaction</th><th class="p-2">Matched user</th><th class="p-2">Account number</th><th class="p-2">Amount</th><th class="p-2">Status</th></tr></thead>
                <tbody>@foreach ($recentPayments as $payment)<tr wire:key="monielinks-review-{{ $payment->id }}" class="border-t border-slate-200 dark:border-slate-700"><td class="break-all p-2">{{ $payment->transaction_reference }}</td><td class="break-all p-2">{{ $payment->account?->user?->name ?? 'Unknown' }}</td><td class="p-2">{{ $payment->event_data['data']['accountnumber'] ?? $payment->event_data['data']['account_number'] ?? 'Unknown' }}</td><td class="whitespace-nowrap p-2">₦{{ \App\Money::format($payment->amount_paid) }}</td><td class="p-2">{{ ucfirst($payment->status) }}</td></tr>@endforeach</tbody>
            </table>
        </div>
    @endif
</section>
