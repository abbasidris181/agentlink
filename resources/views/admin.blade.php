<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @include('theme-script')
        <title>{{ $title }} · {{ config('app.name') }}</title>
        @fonts
        @livewireStyles
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased selection:bg-indigo-100 selection:text-indigo-900 dark:bg-slate-950 dark:text-slate-100">
        @switch($page)
            @case('overview') <livewire:admin.overview /> @break
            @case('notifications') <livewire:admin.notifications :channel="$channel" /> @break
            @case('prices') <livewire:admin.prices /> @break
            @case('settings') <livewire:admin.settings /> @break
            @case('requests') <livewire:admin.requests :request-id="$requestId ?? null" :service="$service ?? null" /> @break
            @case('transactions') <livewire:admin.transactions :transaction-group="$transactionGroup ?? 'all'" /> @break
            @case('users') <livewire:admin.users :user-id="$userId ?? null" /> @break
        @endswitch
        @livewireScripts
    </body>
</html>
