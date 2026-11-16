<div wire:poll.15s.visible>
    @if ($available || $account || $payments->isNotEmpty())
        <section class="mt-5 space-y-4 border-t border-slate-200 pt-5 dark:border-slate-700">
            <h3 class="text-base font-semibold">Monielinks · PalmPay</h3>
            @if ($notice)<p role="status" class="rounded-xl bg-indigo-50 p-3 text-sm text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200">{{ $notice }}</p>@endif
            @if ($errors->any())<div role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
            <p class="text-sm leading-6 text-amber-700 dark:text-amber-300">Monielinks transfers currently need support confirmation before wallet credit. Funding charge: {{ $fundingCharge }}%.</p>
            @if ($account?->status === 'active')
                <x-bank-account-card wire:key="monielinks-bank-{{ $account->id }}" :bank-name="$account->bank" :account-number="$account->account_number" :account-name="$account->account_name" />
            @elseif ($account && $account->status !== 'failed')
                <p role="status" class="text-sm leading-6 text-slate-500 dark:text-slate-400">{{ $account->last_error ?? 'Your account request is being processed. Contact support before trying again.' }}</p>
            @elseif ($available)
                <p class="text-sm leading-6 text-slate-500 dark:text-slate-400">Create a permanent PalmPay account using the name and email saved on your profile.</p>
                <form wire:submit="createAccount">
                    <button type="submit" wire:loading.attr="disabled" class="w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50"><span wire:loading.remove wire:target="createAccount">Create PalmPay account</span><span wire:loading wire:target="createAccount">Creating account…</span></button>
                </form>
            @endif
            @if ($payments->isNotEmpty())
                <div class="space-y-3">
                    <h4 class="text-sm font-semibold">Recent Monielinks deposits</h4>
                    @foreach ($payments as $payment)<div wire:key="monielinks-payment-{{ $payment->id }}" class="rounded-xl bg-slate-50 p-3 text-xs dark:bg-slate-800"><p class="break-all font-mono">{{ $payment->transaction_reference }}</p><p class="mt-1">{{ ucfirst($payment->status) }} · ₦{{ \App\Money::format($payment->amount_paid) }}</p></div>@endforeach
                </div>
            @endif
        </section>
    @endif
</div>
