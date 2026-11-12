<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @include('theme-script')
        <title>Create an account · {{ config('app.name') }}</title>
        @fonts
        @livewireStyles
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased selection:bg-indigo-100 selection:text-indigo-900 dark:bg-slate-950 dark:text-slate-100">
        <main class="flex min-h-screen flex-col">
            <section aria-labelledby="register-heading" class="flex min-h-screen w-full items-center justify-center bg-slate-50 px-6 py-12 sm:px-12 lg:min-h-[calc(100vh-4rem)] lg:w-1/2 lg:p-16 dark:bg-slate-950">
                <div class="w-full max-w-[440px]">
                    <div class="rounded-2xl bg-white p-8 shadow-2xl shadow-slate-400/40 sm:p-10 dark:bg-surface-dark dark:shadow-black/50">
                        <div class="mb-6 flex items-center gap-3">
                            <span class="flex size-9 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-sm" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" class="size-6"><path d="m12 3 8 5-8 5-8-5 8-5Zm-8 9 8 5 8-5M4 16l8 5 8-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <span class="text-lg font-bold tracking-tight">{{ config('app.name') }}</span>
                        </div>
                        <livewire:auth.register />
                    </div>
                </div>
            </section>
        </main>
        @livewireScripts
    </body>
</html>
