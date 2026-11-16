<div wire:poll.15s.visible class="mt-4 space-y-4">
    @if ($available || $account)<h3 class="text-base font-semibold">Monnify</h3>@endif
    @if ($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if ($notice)<p role="status" class="rounded-xl bg-indigo-50 p-3 text-sm text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200">{{ $notice }}</p>@endif
    @if ($mode === 'sandbox' && ($available || $account))
        <p class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200">Sandbox accounts are for testing only. Payments will not credit your wallet.</p>
    @endif
    @if ($account?->status === 'active')
        <p class="text-sm leading-6 text-slate-500 dark:text-slate-400">Transfer to your dedicated account below whenever you want to fund your wallet. Funding charge: <strong>{{ $fundingCharge }}%</strong>. Your wallet receives the transferred amount minus this charge after payment verification.</p>
        @foreach ($account->accounts as $bank)
            <x-bank-account-card wire:key="bank-{{ $bank['bankCode'] }}-{{ $bank['accountNumber'] }}" :bank-name="$bank['bankName']" :account-number="$bank['accountNumber']" :account-name="$bank['accountName']" />
        @endforeach
    @elseif ($available && (! $account || $account->status === 'failed'))
        <p class="text-sm leading-6 text-slate-500 dark:text-slate-400">Create your permanent bank account for wallet funding. Provide your BVN or NIN, or both. Funding charge: <strong>{{ $fundingCharge }}%</strong>.</p>
        <form wire:submit="createAccount" class="space-y-4">
            <fieldset wire:loading.attr="disabled" class="space-y-4 disabled:opacity-60">
                <label class="block text-sm font-medium">BVN<input wire:model="bvn" inputmode="numeric" maxlength="11" autocomplete="off" placeholder="11 digits — required if no NIN" class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800"></label>
                <label class="block text-sm font-medium">NIN<input wire:model="nin" inputmode="numeric" maxlength="11" autocomplete="off" placeholder="11 digits — required if no BVN" class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800"></label>
                <label class="flex items-start gap-2 text-xs leading-5"><input type="checkbox" wire:model="consent" required class="mt-1 size-4 shrink-0 accent-indigo-600">I confirm these identity details belong to me and consent to their use by Monnify to create my reserved bank account.</label>
                <button type="submit" class="w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white">Create bank account</button>
            </fieldset>
        </form>
    @elseif ($account)
        <p class="text-sm leading-6 text-slate-500 dark:text-slate-400">{{ $account->last_error ?? 'Your account request is being processed. Check its status shortly.' }}</p>
    @elseif (! $monielinksAvailable)
        <p class="text-sm leading-6 text-slate-500 dark:text-muted-light">Wallet funding is not available yet. Please check back soon.</p>
    @endif
    @if ($account && $account->status !== 'active')
        <button type="button" wire:click="checkAccount" wire:loading.attr="disabled" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold disabled:opacity-50 dark:border-slate-700">Check account status</button>
    @endif
    @if ($payments->isNotEmpty())
        <section class="border-t border-slate-200 pt-4 dark:border-slate-700">
            <h3 class="text-sm font-semibold">Recent deposits</h3>
            @foreach ($payments as $payment)
                <div wire:key="deposit-{{ $payment->id }}" class="mt-3 rounded-xl bg-slate-50 p-3 text-xs dark:bg-slate-800">
                    <p class="break-all font-mono text-slate-500">{{ $payment->transaction_reference }}</p>
                    <p class="mt-1 font-semibold">{{ ucfirst($payment->status) }}</p>
                    @if ($payment->status === 'credited')
                        <p class="mt-1">Transferred: ₦{{ \App\Money::format($payment->amount_paid) }} · Charge: ₦{{ \App\Money::format($payment->fee_amount) }} · Credited: ₦{{ \App\Money::format($payment->credited_amount) }}</p>
                    @elseif ($payment->last_error)<p class="mt-1 text-amber-700 dark:text-amber-300">{{ $payment->last_error }}</p>@endif
                </div>
            @endforeach
            <button type="button" wire:click="checkPayments" wire:loading.attr="disabled" class="mt-3 text-xs font-semibold text-indigo-600 disabled:opacity-50 dark:text-indigo-300">Recheck pending payments</button>
        </section>
    @endif
</div>
