<div class="mx-auto max-w-4xl">
    <x-service-availability :available="$canSubmit">
    @include('livewire.service-unavailable')
    <a href="{{ route('dashboard') }}" class="mb-6 inline-flex items-center gap-2 text-sm font-medium text-slate-500 transition hover:text-indigo-600 dark:text-muted-light dark:hover:text-indigo-300">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Back to dashboard
    </a>
    <div class="page-banner mb-7 flex items-center gap-4">
        <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300">
            @include('livewire.service-icon', ['serviceId' => 'nin-'.$version, 'iconClass' => 'size-5', 'imageClass' => 'size-12'])
        </span>
        <div>
            <p class="mb-1 text-xs font-semibold uppercase tracking-widest text-indigo-600 dark:text-indigo-400">NIN services</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">NIN-{{ strtoupper($version) }} verification</h1>
        </div>
    </div>
    <section aria-labelledby="nin-form-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-200/60 dark:border-slate-800 dark:bg-surface-dark dark:shadow-black/30">
        <div class="border-b border-slate-100 px-6 py-5 sm:px-8 dark:border-slate-800">
            <h2 id="nin-form-heading" class="text-xl font-semibold">Verification details</h2>
            <p class="mt-1 text-sm leading-6 text-slate-500 dark:text-muted-light">Choose how you want to verify, then enter the required details.</p>
        </div>
        @include('livewire.service-price', ['price' => $price])
        @if ($chargeErrors)
            <p class="px-6 pt-4 text-xs leading-5 text-amber-700 sm:px-8 dark:text-amber-300">Provider verification errors, including no record found, are charged. Server errors, unavailable services, insufficient funds, and connection failures are refunded.</p>
        @endif
        <form wire:submit="verify" class="flex flex-col gap-6 p-6 sm:p-8">
            <div>
                <label for="verification-method" class="mb-2 block text-sm font-medium">Verification method</label>
                <select wire:model.live="method" id="verification-method" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800">
                    <option value="nin">NIN</option>
                    <option value="phone">PHONE</option>
                    <option value="demographics">DEMOGRAPHICS</option>
                </select>
                @error('method') <p role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            @if ($method === 'nin')
                <div wire:key="nin-input">
                    <label for="verification-nin" class="mb-2 block text-sm font-medium">NIN number</label>
                    <input wire:model="nin" id="verification-nin" name="nin" type="text" inputmode="numeric" autocomplete="off" placeholder="Enter your 11-digit NIN" required minlength="11" maxlength="11" pattern="[0-9]{11}" aria-describedby="nin-help nin-error" @error('nin') aria-invalid="true" @enderror class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800 font-mono">
                    <p id="nin-help" class="mt-2 text-xs text-slate-400">Enter exactly 11 digits.</p>
                    @error('nin') <p id="nin-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @elseif ($method === 'phone')
                <div wire:key="phone-input">
                    <label for="verification-phone" class="mb-2 block text-sm font-medium">Phone number</label>
                    <input wire:model="phone" id="verification-phone" name="phone" type="tel" autocomplete="tel" placeholder="08012345678 or +2348012345678" required maxlength="25" aria-describedby="phone-help phone-error" @error('phone') aria-invalid="true" @enderror class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800">
                    <p id="phone-help" class="mt-2 text-xs text-slate-400">Accepted with or without the +234 country code.</p>
                    @error('phone') <p id="phone-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @elseif ($method === 'demographics')
                <div wire:key="demographics-inputs" class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    @foreach (['first_name' => ['First name', 'given-name'], 'surname' => ['Surname', 'family-name'], 'middle_name' => ['Middle name (optional)', 'additional-name']] as $field => $details)
                        <div>
                            <label for="verification-{{ $field }}" class="mb-2 block text-sm font-medium">{{ $details[0] }}</label>
                            <input wire:model="{{ $field }}" id="verification-{{ $field }}" name="{{ $field }}" type="text" autocomplete="{{ $details[1] }}" maxlength="100" @required($field !== 'middle_name') aria-describedby="{{ $field }}-error" @error($field) aria-invalid="true" @enderror class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800">
                            @error($field) <p id="{{ $field }}-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    <div>
                        <label for="verification-dob" class="mb-2 block text-sm font-medium">Date of birth</label>
                        <input wire:model="dob" id="verification-dob" name="dob" type="date" autocomplete="bday" required max="{{ now()->toDateString() }}" aria-describedby="dob-error" @error('dob') aria-invalid="true" @enderror class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800">
                        @error('dob') <p id="dob-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="verification-gender" class="mb-2 block text-sm font-medium">Gender</label>
                        <select wire:model="gender" id="verification-gender" name="gender" required aria-describedby="gender-error" @error('gender') aria-invalid="true" @enderror class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800">
                            <option value="">Select gender</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                        @error('gender') <p id="gender-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif

            <div>
                <label for="verification-consent" class="flex cursor-pointer items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800">
                    <input wire:model="consent" id="verification-consent" name="consent" type="checkbox" value="1" required aria-describedby="consent-error" @error('consent') aria-invalid="true" @enderror class="mt-1 size-4 shrink-0 rounded border-slate-300 accent-indigo-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500 dark:border-slate-600">
                    <span class="text-sm leading-6 text-slate-600 dark:text-slate-300">I consent to the use of the details provided for this NIN verification.</span>
                </label>
                @error('consent') <p id="consent-error" role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="border-t border-slate-100 pt-6 dark:border-slate-800">
                <button type="submit" @disabled(! $canSubmit) wire:loading.attr="disabled" wire:target="verify,method" class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-indigo-500/20 transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-500 disabled:cursor-wait disabled:opacity-60 sm:w-auto">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m12 3 8 4v5c0 4-4 7-8 9-4-2-8-5-8-9V7l8-4Z" stroke-linejoin="round"/><path d="m8 12 3 3 5-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span wire:loading.remove wire:target="verify">Verify</span>
                    <span wire:loading wire:target="verify">Checking details...</span>
                </button>
            </div>
            @if ($showAvailability)
                <p role="status" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-800 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-300">Submission successful. Reference: {{ $submittedReference }}. Your demographics request has been saved for admin review.</p>
            @endif
            @error('verification')
                <p role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm leading-6 text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">{{ $message }}</p>
            @enderror
        </form>
    </section>
    @include('livewire.verification-history', ['serviceName' => 'NIN-'.strtoupper($version), 'resultRoute' => 'nin.'.$version.'.result'])
    @include('livewire.manual-request-history', ['requests' => $manualHistory])
    </x-service-availability>
</div>
