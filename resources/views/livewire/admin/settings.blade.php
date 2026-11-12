<x-admin-shell title="Site settings" section="settings">
    <div class="page-banner mb-7"><h1 class="text-3xl font-bold tracking-tight">Site settings</h1><p class="mt-2 text-sm leading-6 text-slate-500 dark:text-muted-light">Update your website name, customer support details, and dashboard announcement.</p></div>
    <fieldset @disabled(! auth()->user()->can('settings.edit'))>
    <form wire:submit="save" class="max-w-4xl space-y-6">
        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark">
            <h2 class="text-xl font-semibold">Account access</h2>
            <div class="mt-5 flex flex-col gap-5">
                @foreach (['emailVerificationEnabled' => ['Require email verification for new signups', 'New accounts must verify their email before using services. Existing accounts are unaffected. Turning this off lets pending accounts continue without verification.'], 'loginDisabled' => ['Disable user login', 'Block new sign-ins for users, agents, and staff. Super administrators can still sign in. Existing sessions stay active.'], 'registrationDisabled' => ['Disable new user registration', 'Stop new accounts from being created. Existing accounts are unaffected.']] as $field => $details)
                    <label for="setting-{{ $field }}" class="flex cursor-pointer items-start justify-between gap-5">
                        <span><span class="block text-sm font-semibold">{{ $details[0] }}</span><span id="help-{{ $field }}" class="mt-1 block text-xs leading-5 text-slate-500 dark:text-muted-light">{{ $details[1] }}</span></span>
                        <span class="relative mt-1 inline-flex shrink-0"><input id="setting-{{ $field }}" type="checkbox" role="switch" wire:model="{{ $field }}" aria-describedby="help-{{ $field }}" class="peer sr-only"><span aria-hidden="true" class="h-6 w-11 rounded-full bg-slate-300 transition peer-checked:bg-indigo-600 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-4 peer-focus-visible:outline-indigo-500 dark:bg-slate-700"></span><span aria-hidden="true" class="pointer-events-none absolute left-0.5 top-0.5 size-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span></span>
                    </label>
                @endforeach
            </div>
            @if (in_array(config('mail.default'), ['log', 'array'], true))
                <p class="mt-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-950 dark:text-amber-200">Email delivery is not configured. Verification messages currently stay on this server. Configure an email provider before enabling verification.</p>
            @endif
            <p class="mt-5 text-xs leading-5 text-slate-500 dark:text-muted-light">Verification emails use the configured mail service. Save settings to apply these switches.</p>
        </section>
        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark"><h2 class="mb-5 text-xl font-semibold">Brand and support</h2><div class="grid gap-5 sm:grid-cols-2">
<div><label for="setting-siteName" class="mb-2 block text-sm font-medium">Website name</label><input wire:model="siteName" id="setting-siteName" type="text" maxlength="100" required class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></div>
<div><label for="setting-supportEmail" class="mb-2 block text-sm font-medium">Support email</label><input wire:model="supportEmail" id="setting-supportEmail" type="email" maxlength="255"  class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></div>
<div><label for="setting-supportPhone" class="mb-2 block text-sm font-medium">Support phone</label><input wire:model="supportPhone" id="setting-supportPhone" type="tel" maxlength="30"  class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></div>
<div><label for="setting-whatsappNumber" class="mb-2 block text-sm font-medium">WhatsApp number with country code</label><input wire:model="whatsappNumber" id="setting-whatsappNumber" type="tel" maxlength="25"  class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></div>
        </div></section>
        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-surface-dark"><h2 class="mb-2 text-xl font-semibold">Dashboard announcement</h2><p class="mb-5 text-sm text-slate-400">Shown on the customer dashboard. Leave blank to show the default empty state.</p><label for="site-announcement" class="sr-only">Announcement</label><textarea wire:model="announcement" id="site-announcement" rows="5" maxlength="2000" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-base focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/15 dark:border-slate-700 dark:bg-slate-800"></textarea></section>
        <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50">Save settings</button>
    </form>
    </fieldset>
</x-admin-shell>
