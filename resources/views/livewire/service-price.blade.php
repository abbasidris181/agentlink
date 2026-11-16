@if ($price !== null)
    <p class="border-b border-slate-100 px-6 py-4 text-sm font-semibold text-indigo-600 sm:px-8 dark:border-slate-800 dark:text-indigo-300">Price{{ ($perTrackingId ?? false) ? ' per tracking ID' : '' }}: ₦{{ \App\Money::format($price) }}</p>
@endif
