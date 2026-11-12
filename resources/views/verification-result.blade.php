<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @include('theme-script')
        <title>{{ $resultLabel }} verification result · {{ config('app.name') }}</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-surface-dark">
            <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-5 sm:px-8">
                <a href="{{ route($backRoute) }}" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-300">← Back to {{ $resultLabel }}</a>
                <span class="text-sm font-bold">{{ config('app.name') }}</span>
            </div>
        </header>
        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-8">
            @if ($errors->any())
                <ul role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            @endif
            <div class="mb-7 flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-indigo-600 dark:text-indigo-300">{{ $resultLabel }}</p>
                    <h1 class="mt-2 text-2xl font-bold sm:text-3xl">Verification result</h1>
                </div>
                <span class="rounded-full bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Verified successfully</span>
            </div>
            <section aria-label="Verified identity" class="mb-8 max-w-sm">
                <div class="flex flex-col items-center rounded-2xl border border-slate-200 bg-white px-6 py-8 text-center shadow-sm dark:border-slate-800 dark:bg-surface-dark">
                    @if ($photo = $verification->image('photo'))
                        <img src="{{ $photo }}" alt="Photo of {{ $verification->fullName() }}" class="size-40 rounded-full object-cover" width="160" height="160">
                    @else
                        <div class="flex size-40 items-center justify-center rounded-full bg-slate-100 text-sm text-slate-500 dark:bg-slate-800">Photo unavailable</div>
                    @endif
                    <h2 class="mt-5 text-2xl font-semibold">{{ $verification->fullName() }}</h2>
                    <p class="mt-2 font-mono text-sm text-slate-500 dark:text-slate-400">{{ $verification->field('idNumber') }}</p>
                    <p class="mt-7 text-xs font-semibold uppercase tracking-widest text-slate-500 dark:text-slate-400">Signature</p>
                    @if ($signature = $verification->image('signature'))
                        <img src="{{ $signature }}" alt="Signature of {{ $verification->fullName() }}" class="mt-3 h-24 max-w-full rounded-lg bg-white object-contain p-2" width="200" height="96">
                    @else
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Signature unavailable</p>
                    @endif
                </div>
            </section>
            <section aria-labelledby="slips-heading" class="mb-8 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark">
                <h2 id="slips-heading" class="text-lg font-semibold">Download slips</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Personalized {{ $downloadFormat }} slips. Paid slips are charged once per verification; repeat downloads are included.</p>
                @include('slip-download-icons', ['referenceParameter' => 'verification', 'recordReference' => $verification->reference])
            </section>
            <section aria-labelledby="details-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-surface-dark">
                <div class="border-b border-slate-100 p-6 dark:border-slate-800"><h2 id="details-heading" class="text-xl font-semibold">Personal information</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Details returned after verification.</p></div>
                <dl class="grid gap-x-8 p-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($verification->details() as $label => $value)
                        <div class="border-b border-slate-100 py-4 dark:border-slate-800"><dt class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="mt-1 break-words text-sm font-medium">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </section>
        </main>
    </body>
</html>
