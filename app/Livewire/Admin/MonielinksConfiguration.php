<?php

namespace App\Livewire\Admin;

use App\Models\AdminAudit;
use App\Models\MonielinksPayment;
use App\MonielinksSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class MonielinksConfiguration extends AdminComponent
{
    public bool $enabled = false;

    public string $clientId = '';

    public string $secretKey = '';

    public string $webhookSecret = '';

    public string $fundingCharge = '0.00';

    public string $notice = '';

    public function mount(): void
    {
        $settings = MonielinksSettings::get();
        $this->enabled = $settings['enabled'];
        $this->fundingCharge = $settings['funding_charge'];
    }

    public function save(): void
    {
        Gate::authorize('settings.edit');
        $old = MonielinksSettings::get();
        MonielinksSettings::save([
            'enabled' => $this->enabled,
            'client_id' => $this->clientId !== '' ? trim($this->clientId) : $old['client_id'],
            'secret_key' => $this->secretKey !== '' ? trim($this->secretKey) : $old['secret_key'],
            'webhook_secret' => $this->webhookSecret !== '' ? trim($this->webhookSecret) : $old['webhook_secret'],
            'funding_charge' => trim($this->fundingCharge),
        ]);
        AdminAudit::query()->create(['actor_id' => auth()->id(), 'event' => 'monielinks.settings_updated', 'details' => ['enabled' => $this->enabled, 'funding_charge' => $this->fundingCharge]]);
        $this->reset('clientId', 'secretKey', 'webhookSecret');
        $this->notice = 'Monielinks settings saved.';
    }

    public function render(): View
    {
        $settings = MonielinksSettings::get();

        return view('livewire.admin.monielinks-configuration', [
            'clientIdConfigured' => $settings['client_id'] !== '', 'secretKeyConfigured' => $settings['secret_key'] !== '',
            'webhookSecretConfigured' => $settings['webhook_secret'] !== '',
            'recentPayments' => MonielinksPayment::query()->with('account.user')->latest('id')->limit(10)->get(),
        ]);
    }
}
