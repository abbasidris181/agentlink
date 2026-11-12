<label class="flex cursor-pointer items-center gap-2 text-xs font-medium text-red-600 dark:text-red-400">
    <span class="relative inline-flex">
        <input type="checkbox" role="switch" wire:model="unavailableTypes.{{ $priceService }}.{{ $priceMethod }}" aria-label="Mark {{ $typeLabel }} unavailable" class="peer sr-only">
        <span aria-hidden="true" class="h-5 w-9 rounded-full bg-slate-300 transition peer-checked:bg-red-600 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-4 peer-focus-visible:outline-red-500 dark:bg-slate-700"></span>
        <span aria-hidden="true" class="pointer-events-none absolute left-0.5 top-0.5 size-4 rounded-full bg-white shadow transition peer-checked:translate-x-4"></span>
    </span>
    Unavailable
</label>
@error('unavailableTypes.'.$priceService.'.'.$priceMethod)<p role="alert" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
