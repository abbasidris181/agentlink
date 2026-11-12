<div class="mx-auto max-w-4xl">
    <x-service-availability :available="$ninModificationAvailable">
    <a href="{{ route('dashboard') }}" class="mb-6 inline-flex items-center gap-2 text-sm font-medium text-slate-500 transition hover:text-indigo-600 dark:text-muted-light dark:hover:text-indigo-300">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        Back to dashboard
    </a>
    <div class="page-banner mb-7 flex items-center gap-4">
        <span class="flex size-14 shrink-0 items-center justify-center rounded-2xl">
            @include('livewire.service-icon', ['serviceId' => 'nin-modification', 'iconClass' => 'size-5', 'imageClass' => 'size-12'])
        </span>
        <div>
            <p class="mb-1 text-xs font-semibold uppercase tracking-widest">NIN services</p>
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">NIN modification</h1>
        </div>
    </div>
    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2" aria-label="NIN modification services">
        @foreach (['first' => 'First modification', 'second' => 'Second modification'] as $key => $name)
            <article wire:key="nin-modification-{{ $key }}" class="relative flex min-w-0 flex-col items-center gap-5 rounded-3xl border border-slate-200 bg-white p-8 text-center shadow-sm dark:border-slate-800 dark:bg-surface-dark">
                @include('livewire.service-icon', ['serviceId' => 'nin-modification', 'iconClass' => 'size-16', 'imageClass' => 'h-32 w-44'])
                <h2 class="text-xl font-bold tracking-tight sm:text-2xl">{{ $name }}</h2>
                <p class="text-sm text-slate-500 dark:text-muted-light">Open service</p>
                <a href="{{ route('nin.modification.form', ['stage' => $key]) }}" aria-label="Open {{ $name }}" class="absolute inset-0 rounded-3xl focus-visible:outline-2 focus-visible:outline-indigo-500"></a>
            </article>
        @endforeach
    </div>
    </x-service-availability>
</div>
