<?php

namespace App\Livewire\Admin;

use App\Models\AdminAudit;
use App\Models\SiteSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class Settings extends AdminComponent
{
    public string $siteName = '';

    public string $supportEmail = '';

    public string $supportPhone = '';

    public string $whatsappNumber = '';

    public string $whatsappUrl = '';

    public string $announcement = '';

    public bool $emailVerificationEnabled = false;

    public bool $loginDisabled = false;

    public bool $registrationDisabled = false;

    public function mount(): void
    {
        $settings = SiteSetting::query()->pluck('value', 'key');
        $this->siteName = $settings->get('site_name') ?: config('app.name');
        $this->supportEmail = $settings->get('support_email') ?? '';
        $this->supportPhone = $settings->get('support_phone') ?? '';
        $this->whatsappNumber = $settings->get('whatsapp_number') ?? '';
        $this->whatsappUrl = $settings->get('whatsapp_url') ?? '';
        $this->announcement = $settings->get('announcement') ?? '';
        $this->emailVerificationEnabled = $settings->get('email_verification_enabled') === '1';
        $this->loginDisabled = $settings->get('login_disabled') === '1';
        $this->registrationDisabled = $settings->get('registration_disabled') === '1';
    }

    public function save(): void
    {
        Gate::authorize('settings.edit');
        $this->siteName = trim($this->siteName);
        $this->supportEmail = trim($this->supportEmail);
        $this->supportPhone = trim($this->supportPhone);
        $this->whatsappNumber = trim($this->whatsappNumber);
        $this->whatsappUrl = trim($this->whatsappUrl);
        $this->announcement = trim($this->announcement);
        $this->validate([
            'siteName' => ['required', 'string', 'max:100'],
            'supportEmail' => ['nullable', 'email', 'max:255'],
            'supportPhone' => ['nullable', 'string', 'max:30', 'regex:/\A[+0-9 ()-]+\z/'],
            'whatsappUrl' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'whatsappNumber' => ['nullable', 'string', 'max:25', 'regex:/\A\+?[0-9]{7,15}\z/'],
            'announcement' => ['nullable', 'string', 'max:2000'],
            'emailVerificationEnabled' => ['boolean'],
            'loginDisabled' => ['boolean'],
            'registrationDisabled' => ['boolean'],
        ]);

        DB::transaction(function (): void {
            $settings = [
                'site_name' => $this->siteName, 'support_email' => $this->supportEmail,
                'support_phone' => $this->supportPhone, 'whatsapp_number' => $this->whatsappNumber,
                'whatsapp_url' => $this->whatsappUrl,
                'announcement' => $this->announcement,
                'email_verification_enabled' => $this->emailVerificationEnabled ? '1' : '0',
                'login_disabled' => $this->loginDisabled ? '1' : '0',
                'registration_disabled' => $this->registrationDisabled ? '1' : '0',
            ];

            foreach ($settings as $key => $value) {
                SiteSetting::updateOrCreate(['key' => $key], ['value' => $value]);
            }

            AdminAudit::create(['actor_id' => Auth::id(), 'event' => 'settings.updated', 'details' => ['keys' => array_keys($settings)]]);
        });

        config(['app.name' => $this->siteName, 'site.announcement' => $this->announcement, 'services.whatsapp.number' => $this->whatsappNumber, 'services.whatsapp.url' => $this->whatsappUrl]);
        session()->flash('status', 'Site settings saved.');
    }

    public function render(): View
    {
        return view('livewire.admin.settings');
    }
}
