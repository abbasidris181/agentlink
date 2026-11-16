<?php

namespace App\Livewire\Admin;

use App\Models\AdminAudit;
use App\MonnifySettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class MonnifyConfiguration extends AdminComponent
{
    public bool $enabled = false;

    public string $mode = 'sandbox';

    public string $contractCode = '';

    public string $apiKey = '';

    public string $secretKey = '';

    public string $fundingCharge = '0.00';

    public string $notice = '';

    public function mount(): void
    {
        $settings = MonnifySettings::get();
        $this->enabled = $settings['enabled'];
        $this->mode = $settings['mode'];
        $this->contractCode = $settings['contract_code'];
        $this->fundingCharge = $settings['funding_charge'];
    }

    public function save(): void
    {
        Gate::authorize('settings.edit');
        $old = MonnifySettings::get();
        MonnifySettings::save([
            'enabled' => $this->enabled, 'mode' => $this->mode, 'contract_code' => trim($this->contractCode),
            'api_key' => $this->apiKey !== '' ? trim($this->apiKey) : $old['api_key'],
            'secret_key' => $this->secretKey !== '' ? trim($this->secretKey) : $old['secret_key'],
            'funding_charge' => trim($this->fundingCharge),
        ]);
        AdminAudit::query()->create(['actor_id' => auth()->id(), 'event' => 'monnify.settings_updated', 'details' => ['mode' => $this->mode, 'enabled' => $this->enabled, 'funding_charge' => $this->fundingCharge]]);
        $this->reset('apiKey', 'secretKey');
        $this->notice = 'Monnify settings saved.';
    }

    public function render(): View
    {
        $settings = MonnifySettings::get();

        return view('livewire.admin.monnify-configuration', [
            'apiKeyConfigured' => $settings['api_key'] !== '', 'secretKeyConfigured' => $settings['secret_key'] !== '',
        ]);
    }
}
