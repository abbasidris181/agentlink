<div class="mx-auto max-w-4xl">
    <x-service-availability :available="$canSubmit">
    @include('livewire.service-unavailable')
    <a href="{{ route('dashboard') }}" class="mb-6 inline-flex items-center gap-2 text-sm font-medium text-slate-500 transition hover:text-indigo-600 dark:text-muted-light dark:hover:text-indigo-300">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Back to dashboard
    </a>
    <div class="page-banner mb-7 flex items-center gap-4">
        <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300">
            @include('livewire.service-icon', ['serviceId' => 'bvn-verification', 'iconClass' => 'size-5', 'imageClass' => 'size-12'])
        </span>
        <div>
            <p class="mb-1 text-xs font-semibold uppercase tracking-widest text-indigo-600 dark:text-indigo-400">BVN services</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">BVN verification</h1>
        </div>
    </div>
    <section aria-labelledby="bvn-form-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-200/60 dark:border-slate-800 dark:bg-surface-dark dark:shadow-black/30">
        <div class="border-b border-slate-100 px-6 py-5 sm:px-8 dark:border-slate-800">
            <h2 id="bvn-form-heading" class="text-xl font-semibold">Verification details</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500 dark:text-muted-light">Enter your 11-digit BVN to verify your details.</p>
        </div>
        @include('livewire.service-price', ['price' => $price])
        <form wire:submit="verify" class="flex flex-col gap-6 p-6 sm:p-8">
            <div wire:key="bvn-input">
                <label for="verification-bvn" class="mb-2 block text-sm font-medium">BVN number</label>
                <input wire:model="bvn" id="verification-bvn" name="bvn" type="text" inputmode="numeric" autocomplete="off" placeholder="Enter your 11-digit BVN" required minlength="11" maxlength="11" pattern="[0-9]{11}" aria-describedby="bvn-help bvn-error" @error('bvn') aria-invalid="true" @enderror class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800 font-mono">
                <p id="bvn-help" class="mt-2 text-xs text-slate-400">Enter exactly 11 digits.</p>
                @error('bvn') <p id="bvn-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="verification-consent" class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800">
                    <input wire:model="consent" id="verification-consent" name="consent" type="checkbox" value="1" required aria-describedby="consent-error" @error('consent') aria-invalid="true" @enderror class="mt-1 size-4 shrink-0 rounded border-slate-300 accent-indigo-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500 dark:border-slate-600">
                    <span class="text-sm leading-6 text-slate-600 dark:text-slate-300">I consent to the use of the details provided for this BVN verification.</span>
                </label>
                @error('consent') <p id="consent-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="border-t border-slate-100 pt-6 dark:border-slate-800">
                <button type="submit" @disabled(! $canSubmit) wire:loading.attr="disabled" wire:target="verify" class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/20 transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-500 disabled:cursor-wait disabled:opacity-60 sm:w-auto">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m12 3 8 4v5c0 4-4 7-8 9-4-2-8-5-8-9V7l8-4Z" stroke-linejoin="round"/><path d="m8 12 3 3 5-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span wire:loading.remove wire:target="verify">Verify</span>
                    <span wire:loading wire:target="verify">Checking details...</span>
                </button>
            </div>
            @error('verification')
                <p role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm leading-6 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</p>
            @enderror
        </form>
    </section>
    @include('livewire.verification-history', ['serviceName' => 'BVN', 'resultRoute' => 'bvn.result'])
    </x-service-availability>
</div>
