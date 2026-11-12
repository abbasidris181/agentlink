<div class="mx-auto max-w-4xl">
    <x-service-availability :available="$canSubmit">
    <a href="{{ route('dashboard') }}" class="mb-6 inline-flex items-center gap-2 text-sm font-medium text-slate-500 hover:text-indigo-600 dark:text-muted-light dark:hover:text-indigo-300">Back to dashboard</a>
    <div class="page-banner mb-7 flex items-center gap-4">
        <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl">
            @include('livewire.service-icon', ['serviceId' => 'bvn-modification', 'iconClass' => 'size-5', 'imageClass' => 'size-12'])
        </span>
        <div>
            <p class="mb-1 text-xs font-semibold uppercase tracking-widest">BVN services</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">BVN modification</h1>
        </div>
    </div>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 dark:border-slate-800 dark:bg-surface-dark">
        @include('livewire.service-price', ['price' => $price])
        @include('livewire.service-unavailable')
        <form wire:submit="submit" class="flex flex-col gap-6">
            <div>
                <label for="bvn-modification-kind" class="mb-2 block text-sm font-medium">Kind</label>
                <select id="bvn-modification-kind" wire:model.live="kind" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800">
                    <option value="">Select kind</option>
                    @foreach ($kinds as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('kind') <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <fieldset>
                <legend class="mb-2 text-sm font-medium">Type</legend>
                <p class="mb-3 text-xs text-slate-500 dark:text-muted-light">Select one or more modification types.</p>
                <div class="flex flex-wrap gap-3">
                    @foreach (['name' => 'Name', 'dob' => 'DOB', 'phone' => 'Phone number'] as $key => $label)
                        <label for="bvn-modification-type-{{ $key }}" class="inline-flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm dark:border-slate-700">
                            <input id="bvn-modification-type-{{ $key }}" wire:model.live="types" type="checkbox" value="{{ $key }}" class="size-4 rounded accent-indigo-600">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('types') <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                @error('types.*') <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </fieldset>
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                @foreach ($this->fields as $key => $field)
                    <div wire:key="bvn-modification-field-{{ $key }}">
                        <label for="bvn-modification-{{ $key }}" class="mb-2 block text-sm font-medium">{{ $field['label'] }}</label>
                        <input id="bvn-modification-{{ $key }}" wire:model="{{ $key }}" type="{{ $field['type'] }}" required
                            @if (in_array($key, ['bvn', 'nin'], true)) inputmode="numeric" minlength="11" maxlength="11" pattern="[0-9]{11}" autocomplete="off"
                            @elseif ($field['type'] === 'date') max="{{ now()->toDateString() }}"
                            @else maxlength="{{ $field['type'] === 'tel' ? 25 : 100 }}" @endif
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800">
                        @error($key) <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            <button type="submit" @disabled(! $canSubmit) wire:loading.attr="disabled" wire:target="submit,types,kind" class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 sm:self-start">Continue</button>
            @if ($showAvailability)
                <p role="status" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">Submission successful. Reference: {{ $submittedReference }}. Your request has been saved for admin review.</p>
            @endif
        </form>
    </section>
    @include('livewire.manual-request-history', ['requests' => $history])
    </x-service-availability>
</div>
