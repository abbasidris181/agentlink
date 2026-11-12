<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @include('theme-script')
        <title>Wallet transfer · {{ config('app.name') }}</title>
        @fonts
        @livewireStyles
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased selection:bg-indigo-100 selection:text-indigo-900 dark:bg-slate-950 dark:text-slate-100">
        <livewire:dashboard :show-transfer="true" />
        @livewireScripts
    </body>
</html>
