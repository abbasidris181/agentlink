<div class="mx-auto max-w-4xl">
    <x-service-availability :available="$canSubmit">
    <a href="{{ route('nin.modification') }}" class="mb-6 inline-flex items-center gap-2 text-sm font-medium text-slate-500 hover:text-indigo-600 dark:text-muted-light dark:hover:text-indigo-300">← Back to NIN modification</a>
    <div class="page-banner mb-7">
        <p class="mb-1 text-xs font-semibold uppercase tracking-widest">NIN modification</p>
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ ucfirst($stage) }} modification</h1>
    </div>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 dark:border-slate-800 dark:bg-surface-dark">
        @include('livewire.service-price', ['price' => $price])
        @include('livewire.service-unavailable')
        <form wire:submit="submit" class="flex flex-col gap-6">
            <fieldset>
                <legend class="mb-2 text-sm font-medium">Modification type</legend>
                <p class="mb-3 text-xs text-slate-500 dark:text-muted-light">Select one or more modification types.</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach (['name' => 'Name', 'phone' => 'Phone number', 'dob' => 'DOB', 'dob_above_5' => 'DOB above 5 years', 'dob_above_10' => 'DOB above 10 years', 'dob_above_15' => 'DOB above 15 years', 'address' => 'Address', 'gender' => 'Gender'] as $key => $label)
                        <label for="modification-type-{{ $key }}" class="inline-flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm dark:border-slate-700">
                            <input id="modification-type-{{ $key }}" wire:model.live="types" type="checkbox" value="{{ $key }}" class="size-4 rounded accent-indigo-600">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('types') <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                @error('types.*') <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </fieldset>
            <div>
                <label for="modification-nin" class="mb-2 block text-sm font-medium">NIN</label>
                <input id="modification-nin" wire:model="nin" type="text" inputmode="numeric" autocomplete="off" minlength="11" maxlength="11" pattern="[0-9]{11}" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800">
                @error('nin') <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div wire:key="modification-fields-{{ $stage }}" class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                @foreach ($this->fields as $key => $field)
                    <div wire:key="modification-field-{{ $stage }}-{{ $key }}" @class(['sm:col-span-2' => $field['type'] === 'textarea'])>
                        <label for="modification-{{ $key }}" class="mb-2 block text-sm font-medium">{{ $field['label'] }}</label>
                        @if ($field['type'] === 'textarea')
                            <textarea id="modification-{{ $key }}" wire:model="details.{{ $key }}" rows="4" maxlength="1000" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800"></textarea>
                        @elseif ($field['type'] === 'select')
                            <select id="modification-{{ $key }}" wire:model="details.{{ $key }}" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800">
                                <option value="">Select gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                        @else
                            <input id="modification-{{ $key }}" wire:model="details.{{ $key }}" type="{{ $field['type'] }}" @required($field['required']) @if ($field['type'] === 'date') max="{{ now()->toDateString() }}" @else maxlength="{{ $field['type'] === 'tel' ? 25 : 100 }}" @endif class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800">
                        @endif
                        @error('details.'.$key) <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            @if ($this->requiresPhoto)
                <div wire:key="modification-photo-{{ $stage }}">
                    <label for="modification-photo" class="mb-2 block text-sm font-medium">Clear photo</label>
                    <input id="modification-photo" wire:model="photo" type="file" accept="image/jpeg,image/png,image/webp" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm dark:border-slate-700 dark:bg-slate-800">
                    <p class="mt-2 text-xs text-slate-500 dark:text-muted-light">Upload a clear, well-lit photo. JPG, PNG, or WebP, up to 5 MB.</p>
                    <p wire:loading wire:target="photo" role="status" class="mt-2 text-sm text-slate-500">Uploading photo...</p>
                    @error('photo') <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @endif
            <button type="submit" @disabled(! $canSubmit) wire:loading.attr="disabled" wire:target="submit,photo,types" class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 sm:self-start">Continue</button>
            @if ($showAvailability)
                <p role="status" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">Submission successful. Reference: {{ $submittedReference }}. Your request has been saved for admin review.</p>
            @endif
        </form>
    </section>
    @include('livewire.manual-request-history', ['requests' => $history])
    </x-service-availability>
</div>
