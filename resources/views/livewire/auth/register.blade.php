<div>
    <h1 id="register-heading" class="text-3xl font-bold tracking-tight">Create an account</h1>
    <p class="mt-2 text-sm leading-5 text-slate-500 dark:text-muted-light">join us!</p>
    <div class="my-6 h-px bg-slate-200 dark:bg-slate-800"></div>

    @if (config('site.registration_disabled', false))
        <p role="status" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">New user registration is temporarily disabled. Please try again later.</p>
    @else
    <form wire:submit="register" class="flex flex-col gap-5">
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
                <label for="register-first-name" class="mb-1.5 block text-xs font-medium text-slate-700 dark:text-slate-300">First name</label>
                <input wire:model="first_name" id="register-first-name" name="first_name" type="text" autocomplete="given-name" placeholder="First name" required maxlength="100" aria-describedby="first-name-error" @error('first_name') aria-invalid="true" @enderror class="w-full rounded-xl bg-slate-50 px-3.5 py-3 text-base placeholder:text-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-800 dark:focus:bg-slate-800">
                @error('first_name') <p id="first-name-error" role="alert" class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="register-surname" class="mb-1.5 block text-xs font-medium text-slate-700 dark:text-slate-300">Surname</label>
                <input wire:model="surname" id="register-surname" name="surname" type="text" autocomplete="family-name" placeholder="Surname" required maxlength="100" aria-describedby="surname-error" @error('surname') aria-invalid="true" @enderror class="w-full rounded-xl bg-slate-50 px-3.5 py-3 text-base placeholder:text-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-800 dark:focus:bg-slate-800">
                @error('surname') <p id="surname-error" role="alert" class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
        </div>
        <div>
            <label for="register-email" class="mb-1.5 block text-xs font-medium text-slate-700 dark:text-slate-300">Email</label>
            <input wire:model="email" id="register-email" name="email" type="email" autocomplete="username" placeholder="name@enterprise.com" required maxlength="255" aria-describedby="email-error" @error('email') aria-invalid="true" @enderror class="w-full rounded-xl bg-slate-50 px-3.5 py-3 text-base placeholder:text-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-800 dark:focus:bg-slate-800">
            @error('email') <p id="email-error" role="alert" class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="register-phone" class="mb-1.5 block text-xs font-medium text-slate-700 dark:text-slate-300">Phone</label>
            <input wire:model="phone" id="register-phone" name="phone" type="tel" autocomplete="tel" placeholder="09012345678" required maxlength="30" aria-describedby="phone-error" @error('phone') aria-invalid="true" @enderror class="w-full rounded-xl bg-slate-50 px-3.5 py-3 text-base placeholder:text-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-800 dark:focus:bg-slate-800">
            @error('phone') <p id="phone-error" role="alert" class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="register-business" class="mb-1.5 block text-xs font-medium text-slate-700 dark:text-slate-300">Business</label>
            <input wire:model="business" id="register-business" name="business" type="text" autocomplete="organization" placeholder="Your business name" required maxlength="255" aria-describedby="business-error" @error('business') aria-invalid="true" @enderror class="w-full rounded-xl bg-slate-50 px-3.5 py-3 text-base placeholder:text-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-800 dark:focus:bg-slate-800">
            @error('business') <p id="business-error" role="alert" class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="register-address" class="mb-1.5 block text-xs font-medium text-slate-700 dark:text-slate-300">Address</label>
            <textarea wire:model="address" id="register-address" name="address" autocomplete="street-address" placeholder="Your street address" required maxlength="1000" rows="3" aria-describedby="address-error" @error('address') aria-invalid="true" @enderror class="w-full resize-y rounded-xl bg-slate-50 px-3.5 py-3 text-base placeholder:text-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-800 dark:focus:bg-slate-800"></textarea>
            @error('address') <p id="address-error" role="alert" class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div x-data="{ showPassword: false }">
            <label for="register-password" class="mb-1.5 block text-xs font-medium text-slate-700 dark:text-slate-300">Password</label>
            <div class="flex items-center rounded-xl bg-slate-50 focus-within:ring-2 focus-within:ring-indigo-500 dark:bg-slate-800">
                <input wire:model="password" id="register-password" name="password" type="password" x-bind:type="showPassword ? 'text' : 'password'" autocomplete="new-password" placeholder="Create a password" required minlength="8" maxlength="72" aria-describedby="password-help password-error" @error('password') aria-invalid="true" @enderror class="min-w-0 flex-1 bg-transparent px-3.5 py-3 text-base placeholder:text-slate-400 focus:outline-none">
                <button type="button" x-on:click="showPassword = !showPassword" x-bind:aria-pressed="showPassword" aria-controls="register-password" class="mr-2 rounded-lg px-2 py-2 text-xs font-medium text-slate-500 hover:text-indigo-600 focus-visible:outline-2 focus-visible:outline-indigo-500 dark:text-muted-light">
                    <span x-text="showPassword ? 'Hide' : 'Show'">Show</span><span class="sr-only"> password</span>
                </button>
            </div>
            <p id="password-help" class="mt-2 text-xs leading-5 text-slate-500 dark:text-muted-light">Use at least 8 characters.</p>
            @error('password') <p id="password-error" role="alert" class="mt-1.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="register-password-confirmation" class="mb-1.5 block text-xs font-medium text-slate-700 dark:text-slate-300">Confirm password</label>
            <input wire:model="password_confirmation" id="register-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" placeholder="Re-enter your password" required minlength="8" maxlength="72" class="w-full rounded-xl bg-slate-50 px-3.5 py-3 text-base placeholder:text-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-800 dark:focus:bg-slate-800">
        </div>
        <p wire:offline class="text-xs text-red-600 dark:text-red-400" role="status">You are offline. Reconnect before creating your account.</p>
        <button type="submit" wire:loading.attr="disabled" wire:target="register" class="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-500/20 transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-indigo-500 active:scale-[0.99] disabled:cursor-wait disabled:opacity-60">
            <span wire:loading.remove wire:target="register">Create account</span>
            <span wire:loading wire:target="register" role="status">Creating your account...</span>
        </button>
    </form>
    @endif
    <p class="mt-8 text-center text-xs text-slate-500 dark:text-muted-light">Already have an account? <a href="{{ route('home') }}" class="font-semibold text-indigo-600 hover:text-indigo-700 dark:text-indigo-400">Sign in</a></p>
</div>
