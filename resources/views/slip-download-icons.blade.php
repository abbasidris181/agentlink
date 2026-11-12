<div class="mt-3 flex flex-wrap gap-3" aria-label="Slip downloads">
    @foreach ($slips as $key => $slip)
        <div class="w-16 text-center">
            @if ($slip['purchased'] || $slip['price'] === 0)
                <a href="{{ route($slipRoute, [$referenceParameter => $recordReference, 'slip' => $key]) }}" title="Download {{ $slip['label'] }}" aria-label="Download {{ $slip['label'] }}" class="inline-flex rounded-md p-1 transition hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:hover:bg-slate-800">
                    <img src="{{ asset('assets/card_and_Slip/'.$slip['sample']) }}" alt="" class="h-7 w-11 object-contain">
                </a>
            @elseif ($slip['price'] !== null)
                <form method="POST" action="{{ route($slipRoute.'.purchase', [$referenceParameter => $recordReference, 'slip' => $key]) }}">
                    @csrf
                    <input type="hidden" name="expected_price" value="{{ $slip['price'] }}">
                    <button type="submit" title="Buy &amp; download {{ $slip['label'] }}" aria-label="Buy &amp; download {{ $slip['label'] }} for ₦{{ \App\Money::format($slip['price']) }}" class="inline-flex rounded-md p-1 transition hover:bg-indigo-50 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:hover:bg-slate-800">
                        <img src="{{ asset('assets/card_and_Slip/'.$slip['sample']) }}" alt="" class="h-7 w-11 object-contain">
                    </button>
                </form>
            @else
                <span class="inline-flex rounded-md p-1 opacity-40" title="{{ $slip['label'] }} unavailable">
                    <img src="{{ asset('assets/card_and_Slip/'.$slip['sample']) }}" alt="" class="h-7 w-11 object-contain">
                </span>
            @endif
            <p class="text-[10px] leading-4">{{ $slip['label'] }}</p>
            <p class="text-[10px] leading-4 text-slate-500 dark:text-muted-light">{{ $slip['purchased'] ? 'Purchased' : ($slip['price'] === null ? 'Unavailable' : ($slip['price'] === 0 ? 'Free' : '₦'.\App\Money::format($slip['price']))) }}</p>
        </div>
    @endforeach
</div>
