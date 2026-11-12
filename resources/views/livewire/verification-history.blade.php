<section aria-labelledby="verification-history-heading" class="mt-8 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-200/60 dark:border-slate-800 dark:bg-surface-dark dark:shadow-black/30">
    <div class="border-b border-slate-100 px-6 py-5 sm:px-8 dark:border-slate-800">
        <h2 id="verification-history-heading" class="text-xl font-semibold">Verification history</h2>
        <p class="mt-1 text-sm leading-6 text-slate-500 dark:text-muted-light">Your {{ $serviceName }} verification transactions.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-2xl text-left text-sm">
            <caption class="sr-only">{{ $serviceName }} verification history</caption>
            <thead class="border-b border-slate-100 bg-slate-50 text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-800/60 dark:text-muted-light">
                <tr>
                    <th scope="col" class="whitespace-nowrap px-6 py-4 font-semibold sm:px-8">Transaction id</th>
                    <th scope="col" class="px-6 py-4 font-semibold">Method</th>
                    <th scope="col" class="px-6 py-4 font-semibold">Status</th>
                    <th scope="col" class="px-6 py-4 font-semibold">Date</th>
                    <th scope="col" class="px-6 py-4 font-semibold sm:px-8">Price</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($history ?? [] as $verification)
                    <tr class="border-b border-slate-100 dark:border-slate-800">
                        <td class="px-6 py-4 font-mono text-xs sm:px-8">
                            @if ($verification->status === 'successful')
                                <a href="{{ route($resultRoute ?? 'nin.v1.result', ['verification' => $verification->reference]) }}" class="font-semibold text-indigo-600 underline dark:text-indigo-300">{{ $verification->reference }}</a>
                            @else
                                {{ $verification->reference }}
                            @endif
                        </td>
                        <td class="px-6 py-4">{{ match ($verification->method) { 'bvn' => 'BVN number', 'phone' => 'Phone number', default => 'NIN number' } }}</td>
                        <td class="px-6 py-4">{{ $verification->status === 'charged_error' ? 'Failed (charged)' : ucfirst($verification->status) }}</td>
                        <td class="whitespace-nowrap px-6 py-4">{{ $verification->created_at->format('d M Y, H:i') }}</td>
                        <td class="px-6 py-4 sm:px-8">₦{{ \App\Money::format($verification->price) }}{{ $verification->status === 'failed' ? ' (refunded)' : '' }}</td>
                    </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-12 text-center sm:px-8">
                        <p class="font-medium text-slate-600 dark:text-slate-300">No verifications yet</p>
                        <p class="mt-2 text-xs leading-5 text-slate-400">Your {{ $serviceName }} transactions will appear here after a verification is performed.</p>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
