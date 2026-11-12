@if ($requests->isNotEmpty())
    <section class="mt-8 rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-surface-dark">
        <h2 class="mb-4 text-lg font-semibold">Service request history</h2>
        <div class="space-y-4">
            @foreach ($requests as $request)
                <article wire:key="manual-request-{{ $request->id }}" class="rounded-xl border border-slate-200 p-4 text-sm dark:border-slate-700">
                    <div class="flex flex-wrap justify-between gap-2"><span class="break-all font-medium">{{ $request->reference }}</span><span>{{ $request->statusLabel() }}</span></div>
                    <p class="mt-2">{{ $request->methodLabel() }} · {{ $request->quoted_price === null ? 'Not set' : '₦'.\App\Money::format($request->quoted_price) }}</p>
                    @if ($request->reply)<p class="mt-3 whitespace-pre-wrap break-words">{{ $request->reply }}</p>@endif
                </article>
            @endforeach
        </div>
    </section>
@endif
