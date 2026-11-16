<div class="mx-auto max-w-4xl">
    <x-service-availability :available="$canSubmit">
    @include('livewire.service-unavailable')
    @include('livewire.service-price', ['price' => $price])
    <a href="{{ route('dashboard') }}" class="mb-6 inline-flex items-center gap-2 text-sm font-medium text-slate-500 hover:text-indigo-600 dark:text-muted-light dark:hover:text-indigo-300">Back to dashboard</a>
    <div class="page-banner mb-7 flex items-center gap-4">
        <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl">
            @include('livewire.service-icon', ['serviceId' => 'personalization', 'iconClass' => 'size-5', 'imageClass' => 'size-12'])
        </span>
        <div>
            <p class="mb-1 text-xs font-semibold uppercase tracking-widest">NIN services</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Personalization</h1>
        </div>
    </div>
    <section aria-labelledby="personalization-heading" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 dark:border-slate-800 dark:bg-surface-dark">
        <h2 id="personalization-heading" class="mb-5 text-xl font-semibold">Submit tracking ID</h2>
        <form wire:submit="submit" class="flex flex-col gap-5">
            <div>
                <label for="personalization-tracking" class="mb-2 block text-sm font-medium">Tracking ID</label>
                <input id="personalization-tracking" wire:model="trackingId" placeholder="Enter your 15-character tracking ID" type="text" autocomplete="off" minlength="15" maxlength="15" pattern="[A-Za-z0-9]{15}" required aria-describedby="personalization-tracking-help" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-base dark:border-slate-700 dark:bg-slate-800">
                <p id="personalization-tracking-help" class="mt-2 text-xs text-slate-500 dark:text-muted-light">Enter exactly 15 letters or numbers.</p>
                @error('trackingId') <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <button type="submit" @disabled(! $canSubmit) wire:loading.attr="disabled" wire:target="submit,checkResult" class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 sm:self-start">
                <span wire:loading.remove wire:target="submit">Submit</span>
                <span wire:loading wire:target="submit">Submitting...</span>
            </button>
        </form>
    </section>
    @if ($notice !== '')
        <p role="status" class="mt-5 rounded-xl bg-indigo-50 p-4 text-sm text-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-200">{{ $notice }}</p>
    @endif
    @error('personalization') <p role="alert" class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ $message }}</p> @enderror

    @if ($selected !== null)
        <section aria-labelledby="personalization-result-heading" class="mt-7 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8 dark:border-slate-800 dark:bg-surface-dark">
            <h2 id="personalization-result-heading" class="text-xl font-semibold">Personalization result</h2>
            <p class="mt-2 break-all font-mono text-sm">{{ $selected->tracking_id }}</p>
            <p class="mt-2 text-sm font-semibold">Status: {{ $selected->statusLabel() }}</p>
            @if ($selected->status === 'successful' && $selected->result !== null)
                @if ($photo = $selected->image('photo'))
                    <img src="{{ $photo }}" alt="Personalization portrait" width="144" height="176" class="mt-5 h-44 w-36 rounded-xl object-cover">
                @else
                    <div class="mt-5 flex h-44 w-36 items-center justify-center rounded-xl bg-slate-100 p-4 text-center text-sm text-slate-500 dark:bg-slate-800 dark:text-muted-light">Photo unavailable</div>
                @endif
                <div class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800">
                    <h3 class="text-sm font-semibold">Download slips</h3>
                    @include('slip-download-icons', ['slipRoute' => 'personalization.slip', 'referenceParameter' => 'personalization', 'recordReference' => $selected->reference])
                </div>
                <dl class="mt-6 grid grid-cols-1 gap-5 sm:grid-cols-2">
                    @foreach ($selected->details() as $label => $value)
                        <div class="min-w-0">
                            <dt class="text-xs font-semibold text-slate-500 dark:text-muted-light">{{ $label }}</dt>
                            <dd class="mt-1 break-words text-sm">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @elseif ($selected->status === 'failed')
                <p class="mt-4 text-sm text-red-600 dark:text-red-400">The provider marked this personalization request as failed.</p>
            @else
                <p class="mt-4 text-sm text-slate-500 dark:text-muted-light">Your result is not ready yet. Check again later.</p>
                <button type="button" wire:click="checkResult('{{ $selected->reference }}')" wire:loading.attr="disabled" wire:target="checkResult,submit" class="mt-4 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">Check result</button>
            @endif
        </section>
    @endif
    <section aria-labelledby="personalization-history-heading" class="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-surface-dark">
        <h2 id="personalization-history-heading" class="px-6 py-5 text-xl font-semibold">Personalization history</h2>
        <div class="overflow-x-auto">
            <table class="w-full min-w-xl text-left text-sm">
                <thead class="bg-slate-50 text-slate-500 dark:bg-slate-800 dark:text-muted-light">
                    <tr><th scope="col" class="px-6 py-4">Tracking ID</th><th scope="col" class="px-6 py-4">Status</th><th scope="col" class="px-6 py-4">Submitted</th><th scope="col" class="px-6 py-4">Action</th></tr>
                </thead>
                <tbody>
                    @forelse ($history as $request)
                        <tr wire:key="personalization-{{ $request->reference }}" class="border-t border-slate-100 dark:border-slate-800">
                            <td class="px-6 py-4 font-mono">{{ $request->tracking_id }}</td>
                            <td class="px-6 py-4">{{ $request->statusLabel() }}</td>
                            <td class="whitespace-nowrap px-6 py-4">{{ $request->created_at->format('d M Y, H:i') }}</td>
                            <td class="px-6 py-4">
                                <button type="button" wire:click="checkResult('{{ $request->reference }}')" wire:loading.attr="disabled" wire:target="checkResult,submit" class="whitespace-nowrap rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">Check status</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-8 text-center text-slate-500 dark:text-muted-light">No personalization requests yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    </x-service-availability>
</div>
