<div class="mx-auto max-w-4xl">
    <x-service-availability :available="$canSubmit">
        @include('livewire.service-unavailable')
        <a href="{{ route('dashboard') }}" class="mb-6 inline-flex text-sm font-medium text-slate-500 hover:text-indigo-600 dark:text-muted-light dark:hover:text-indigo-300">Back to dashboard</a>
        <div class="page-banner mb-7 flex items-center gap-4">
            <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl">
                @include('livewire.service-icon', ['serviceId' => 'demographics-search', 'iconClass' => 'size-5', 'imageClass' => 'size-12'])
            </span>
            <div>
                <p class="mb-1 text-xs font-semibold uppercase tracking-widest">NIN services</p>
                <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Demographics Search</h1>
            </div>
        </div>
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-surface-dark">
            @include('livewire.service-price', ['price' => $price])
            <form wire:submit="submit" class="flex flex-col gap-6 p-6 sm:p-8">
                <div class="grid gap-5 sm:grid-cols-2">
                    @foreach (['first_name' => 'First name', 'last_name' => 'Last name', 'middle_name' => 'Middle name (optional)'] as $field => $label)
                        <div>
                            <label for="demographics-{{ $field }}" class="mb-2 block text-sm font-medium">{{ $label }}</label>
                            <input id="demographics-{{ $field }}" wire:model="details.{{ $field }}" placeholder="Enter {{ strtolower($label) }}" type="text" maxlength="100" @required($field !== 'middle_name') class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800">
                            @error('details.'.$field)<p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                    <div>
                        <label for="demographics-dob" class="mb-2 block text-sm font-medium">Date of birth</label>
                        <input id="demographics-dob" wire:model="details.dob" type="text" placeholder="DD-MM-YYYY" minlength="10" maxlength="10" pattern="[0-9]{2}-[0-9]{2}-[0-9]{4}" aria-describedby="demographics-dob-help" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800">
                        <p id="demographics-dob-help" class="mt-2 text-xs text-slate-500 dark:text-muted-light">Use DD-MM-YYYY, for example 31-12-1990.</p>
                        @error('details.dob')<p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="demographics-gender" class="mb-2 block text-sm font-medium">Gender</label>
                        <select id="demographics-gender" wire:model="details.gender" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800">
                            <option value="">Select gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                        @error('details.gender')<p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                    </div>
                </div>
                <button type="submit" @disabled(! $canSubmit) wire:loading.attr="disabled" wire:target="submit" class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50 sm:self-start">Submit request</button>
                @if ($showAvailability)
                    <p role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">Submission successful. Reference: {{ $submittedReference }}. Your search request has been saved for admin review.</p>
                @endif
            </form>
        </section>
        @include('livewire.manual-request-history', ['requests' => $history])
    </x-service-availability>
</div>
