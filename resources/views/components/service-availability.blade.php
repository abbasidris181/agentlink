@props(['available'])
<div>
    @unless ($available)
        <p role="alert" class="mb-6 rounded-xl border border-red-200 bg-red-50 px-5 py-4 text-sm font-semibold text-red-700 dark:border-red-800 dark:bg-red-500/10 dark:text-red-300">Temporarily unavailable. Please try again later. <span class="block pt-1 font-normal">New submissions are blocked for this option. You can still view history and check existing requests.</span></p>
    @endunless
    {{ $slot }}
</div>
