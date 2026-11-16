<x-admin-shell title="Prices" section="prices">
    <div class="page-banner mb-7"><h1 class="text-3xl font-bold tracking-tight">Service prices</h1><p class="mt-2 max-w-3xl text-sm leading-6 text-slate-500 dark:text-muted-light">Set prices for each service type. Disabled services are hidden; unavailable services remain open for history and status checks, with only new submissions blocked. Each service type and slip also has its own Unavailable toggle. Leave a field blank for an unset price; enter 0 for a free service. Instant IPE bulk pricing is per tracking ID. Selecting multiple types adds their prices. Changes take effect when you save; disabling a service retains its prices.</p></div>
    <fieldset @disabled(! auth()->user()->can('prices.edit'))>
    <form wire:submit="save" class="flex flex-col gap-6">
        <div class="grid gap-5 xl:grid-cols-2">
            @foreach ($catalog as $service => $definition)
                <section wire:key="pricing-{{ $service }}" @class(['rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark', 'xl:col-span-2' => in_array($service, ['nin-modification', 'bvn-modification'], true)])>
                    <div class="mb-5 flex items-start justify-between gap-4">
                        <div><h2 class="text-xl font-bold">{{ $definition['label'] }}</h2><p class="mt-1 text-xs text-slate-500 dark:text-muted-light">{{ ! $enabledServices[$service] ? 'Hidden from users' : ($unavailableServices[$service] ? 'New submissions temporarily unavailable' : 'Available to users') }}</p></div>
                        <label for="service-enabled-{{ $service }}" class="flex shrink-0 cursor-pointer items-center gap-3">
                            <span class="text-xs font-medium">Enabled</span>
                            <span class="relative inline-flex"><input id="service-enabled-{{ $service }}" type="checkbox" role="switch" wire:model.live="enabledServices.{{ $service }}" aria-label="Enable {{ $definition['label'] }}" class="peer sr-only"><span aria-hidden="true" class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-indigo-600 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-4 peer-focus-visible:outline-indigo-500 dark:bg-slate-700"></span><span aria-hidden="true" class="pointer-events-none absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span></span>
                        </label>
                    </div>
                    <label class="mb-5 flex cursor-pointer items-center gap-3 text-sm font-medium text-red-600 dark:text-red-400">
                        <span class="relative inline-flex"><input type="checkbox" role="switch" wire:model.live="unavailableServices.{{ $service }}" aria-label="Mark {{ $definition['label'] }} temporarily unavailable" class="peer sr-only"><span aria-hidden="true" class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-red-600 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-4 peer-focus-visible:outline-red-500 dark:bg-slate-700"></span><span aria-hidden="true" class="pointer-events-none absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span></span>
                        Unavailable
                    </label>
                    <div @class(['grid gap-4 sm:grid-cols-2', 'lg:grid-cols-3' => in_array($service, ['nin-modification', 'bvn-modification'], true)])>
                        @foreach ($definition['methods'] as $method => $label)
                            <div>
                                <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                                    <label for="price-{{ $service }}-{{ $method }}" class="text-sm font-medium">{{ $label }} (₦)</label>
                                    @include('livewire.admin.type-unavailable', ['priceService' => $service, 'priceMethod' => $method, 'typeLabel' => $definition['label'].' '.$label])
                                    @if (in_array($service, \App\Models\ServicePrice::NIN_SERVICES, true))
                                        <label for="charge-error-{{ $service }}-{{ $method }}" class="flex cursor-pointer items-center gap-2">
                                            <span class="text-xs font-medium">Charge error</span>
                                            <span class="relative inline-flex"><input id="charge-error-{{ $service }}-{{ $method }}" type="checkbox" role="switch" wire:model="chargeErrors.{{ $service }}.{{ $method }}" aria-label="Charge error for {{ $definition['label'] }} {{ $label }}" class="peer sr-only"><span aria-hidden="true" class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-indigo-600 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-4 peer-focus-visible:outline-indigo-500 dark:bg-slate-700"></span><span aria-hidden="true" class="pointer-events-none absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span></span>
                                        </label>
                                    @endif
                                </div>
                                <input wire:model="prices.{{ $service }}.{{ $method }}" id="price-{{ $service }}-{{ $method }}" type="text" inputmode="decimal" placeholder="Not set" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800">
                                @error('prices.'.$service.'.'.$method)<p role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                    @if (in_array($service, \App\Models\ServicePrice::NIN_SERVICES, true))
                        <p class="mt-4 text-xs leading-5 text-slate-500 dark:text-muted-light">Charge error keeps the verification fee for provider rejections such as no record found. Server errors, unavailable services, insufficient funds, and connection failures are refunded. Changes apply after saving.</p>
                    @endif
                </section>
            @endforeach
        </div>
        <div class="grid gap-5 xl:grid-cols-2">
            @foreach ($slipCatalog as $service => $definition)
                <section wire:key="slip-pricing-{{ $service }}" @class(['rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark', 'xl:col-span-2' => in_array($service, ['nin-modification', 'bvn-modification'], true)])>
                    <h2 class="text-xl font-bold">{{ $definition['label'] }}</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500 dark:text-muted-light">Set each slip’s download price. Enter 0 for free, or leave blank to make it unavailable. Paid slips are charged once per verification; repeat downloads are included.</p>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        @foreach ($definition['slips'] as $slip => $details)
                            <div>
                                <img src="{{ asset('assets/card_and_Slip/'.$details['sample']) }}" alt="{{ $details['label'] }} sample preview" class="mb-3 h-16 w-28 rounded-md bg-white object-contain" loading="lazy">
                                <label for="slip-price-{{ $service }}-{{ $slip }}" class="mb-2 block text-sm font-medium">{{ $details['label'] }} (₦)</label>
                                <div class="mb-3">@include('livewire.admin.type-unavailable', ['priceService' => $service, 'priceMethod' => $slip, 'typeLabel' => $definition['label'].' '.$details['label']])</div>
                                <input wire:model="slipPrices.{{ $service }}.{{ $slip }}" id="slip-price-{{ $service }}-{{ $slip }}" type="text" inputmode="decimal" placeholder="Not set" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800">
                                @error('slipPrices.'.$service.'.'.$slip)<p role="alert" class="mt-2 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
        <div><button type="submit" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50">Save prices and availability</button></div>
    </form>
    </fieldset>
</x-admin-shell>
