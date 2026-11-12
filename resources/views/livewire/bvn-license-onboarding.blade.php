<div class="mx-auto max-w-4xl">
    <x-service-availability :available="$canSubmit">
        @include('livewire.service-unavailable')
        <a href="{{ route('dashboard') }}" class="mb-6 inline-flex text-sm font-medium text-slate-500 hover:text-indigo-600 dark:text-muted-light dark:hover:text-indigo-300">Back to dashboard</a>
        <div class="page-banner mb-7 flex items-center gap-4">
            <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl">
                @include('livewire.service-icon', ['serviceId' => 'bvn-license-onboarding', 'iconClass' => 'size-5', 'imageClass' => 'size-12'])
            </span>
            <div>
                <p class="mb-1 text-xs font-semibold uppercase tracking-widest">BVN services</p>
                <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">BVN license onboarding</h1>
                <p class="mt-2 text-sm text-slate-500 dark:text-muted-light">Android BVN enrollment user requirements</p>
            </div>
        </div>
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-surface-dark">
            @include('livewire.service-price', ['price' => $price])
            <form wire:submit="submit" class="flex flex-col gap-6 p-6 sm:p-8">
                <div>
                    <h2 class="text-xl font-semibold">Agent details</h2>
                    <p class="mt-2 text-sm text-slate-500 dark:text-muted-light">Complete all fields. Your region is filled automatically when you select a state.</p>
                </div>
                <div class="grid gap-5 sm:grid-cols-2">
                    @foreach ($fields as $field => $label)
                        <div wire:key="onboarding-{{ $field }}" @class(['sm:col-span-2' => $field === 'address'])>
                            <label for="onboarding-{{ $field }}" class="mb-2 block text-sm font-medium">{{ $label }}</label>
                            @if ($field === 'state')
                                <select id="onboarding-state" wire:model.live="details.state" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800">
                                    <option value="">Select state or FCT</option>
                                    @foreach ($states as $value => $name)<option value="{{ $value }}">{{ $name }}</option>@endforeach
                                </select>
                            @elseif ($field === 'lga')
                                <select wire:key="onboarding-lga-{{ $selectedState }}" id="onboarding-lga" wire:model="details.lga" required @disabled($lgas === []) class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base disabled:opacity-50 dark:border-slate-700 dark:bg-slate-800">
                                    <option value="">{{ $lgas === [] ? 'Select a state first' : 'Select LGA' }}</option>
                                    @foreach ($lgas as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach
                                </select>
                            @elseif ($field === 'region')
                                <input id="onboarding-region" value="{{ $this->region }}" readonly aria-readonly="true" placeholder="Select a state to see its region" class="w-full rounded-xl border border-slate-200 bg-slate-100 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-900">
                            @elseif ($field === 'address')
                                <textarea id="onboarding-address" wire:model="details.address" rows="3" maxlength="1000" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800"></textarea>
                            @else
                                <input id="onboarding-{{ $field }}" wire:model="details.{{ $field }}" type="{{ match ($field) { 'dob' => 'date', 'email' => 'email', 'phone' => 'tel', default => 'text' } }}" required
                                    @if ($field === 'dob') max="{{ now()->toDateString() }}"
                                    @elseif (in_array($field, ['bvn', 'account_number'], true)) inputmode="numeric" minlength="{{ $field === 'bvn' ? 11 : 10 }}" maxlength="{{ $field === 'bvn' ? 11 : 10 }}" pattern="[0-9]{{ $field === 'bvn' ? '{11}' : '{10}' }}" autocomplete="off"
                                    @else maxlength="{{ match ($field) { 'email' => 254, 'phone' => 25, 'account_name' => 200, default => 150 } }}" @endif
                                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800">
                            @endif
                            @error('details.'.$field)<p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
                <button type="submit" @disabled(! $canSubmit) wire:loading.attr="disabled" wire:target="submit,details.state" class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50 sm:self-start">Submit request</button>
                @if ($showAvailability)
                    <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">Submission successful. Reference: {{ $submittedReference }}. Your request has been saved for admin review.</p>
                @endif
            </form>
        </section>
        @include('livewire.manual-request-history', ['requests' => $history])
    </x-service-availability>
</div>
