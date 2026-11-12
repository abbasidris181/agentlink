<div class="mx-auto max-w-4xl">
    <div class="page-banner mb-7">
        <p class="mb-1 text-xs font-semibold uppercase tracking-widest">Support</p>
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Complains</h1>
    </div>
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 dark:border-slate-800 dark:bg-surface-dark">
        <form wire:submit="submit" class="flex flex-col gap-6">
            <fieldset>
                <legend class="mb-2 text-sm font-medium">Type</legend>
                <p class="mb-3 text-xs text-slate-500 dark:text-muted-light">Select the services your complaint is about, or choose Other.</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ($options as $key => $label)
                        <label for="complaint-type-{{ $loop->index }}" class="inline-flex cursor-pointer items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm dark:border-slate-700">
                            <input id="complaint-type-{{ $loop->index }}" wire:model="types" type="checkbox" value="{{ $key }}" class="size-4 rounded accent-indigo-600">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('types') <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                @error('types.*') <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </fieldset>
            <div>
                <label for="complaint-message" class="mb-2 block text-sm font-medium">Complain</label>
                <textarea id="complaint-message" wire:model="complaint" rows="6" maxlength="5000" required placeholder="Describe your complaint" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base dark:border-slate-700 dark:bg-slate-800"></textarea>
                @error('complaint') <p role="alert" class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="submit" class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50 sm:self-start">Submit complaint</button>
            @if ($submittedReference !== '')
                <p role="status" class="rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300">Complaint submitted successfully. Reference: <span class="break-all font-mono">{{ $submittedReference }}</span>.</p>
            @endif
        </form>
    </section>
    <section aria-labelledby="complaint-history-heading" class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8 dark:border-slate-800 dark:bg-surface-dark">
        <h2 id="complaint-history-heading" class="text-xl font-semibold">Your complaints</h2>
        <div class="mt-5 flex flex-col gap-5">
            @forelse ($history as $request)
                <article wire:key="complaint-{{ $request->reference }}" class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="break-all font-mono text-xs">{{ $request->reference }}</p>
                        <span class="text-xs font-semibold text-indigo-600 dark:text-indigo-300">{{ $request->statusLabel() }}</span>
                    </div>
                    <p class="mt-2 break-words text-sm font-semibold">{{ $request->payload['types'] }}</p>
                    <p class="mt-3 whitespace-pre-wrap break-words text-sm">{{ $request->payload['complaint'] }}</p>
                    <p class="mt-4 text-xs text-slate-500 dark:text-muted-light">{{ $request->created_at->format('d M Y, H:i') }}</p>
                    <div class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-700">
                        <h3 class="text-sm font-semibold">Admin reply</h3>
                        <p class="mt-2 whitespace-pre-wrap break-words text-sm">{{ $request->reply ?: 'Awaiting admin reply' }}</p>
                    </div>
                </article>
            @empty
                <p class="text-sm text-slate-500 dark:text-muted-light">No complaints yet.</p>
            @endforelse
        </div>
    </section>
</div>
