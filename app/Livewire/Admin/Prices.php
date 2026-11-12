<?php

namespace App\Livewire\Admin;

use App\Models\AdminAudit;
use App\Models\ServicePrice;
use App\Models\SiteSetting;
use App\Money;
use App\ServiceAvailability;
use App\ServiceCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class Prices extends AdminComponent
{
    /** @var array<string, array<string, string>> */
    public array $prices = [];

    /** @var array<string, bool> */
    public array $enabledServices = [];

    /** @var array<string, bool> */
    public array $unavailableServices = [];

    /** @var array<string, array<string, bool>> */
    public array $unavailableTypes = [];

    /** @var array<string, array<string, bool>> */
    public array $chargeErrors = [];

    /** @var array<string, array<string, string>> */
    public array $slipPrices = [];

    public function mount(): void
    {
        $existing = ServicePrice::query()->get()->keyBy(fn (ServicePrice $price): string => $price->service.'.'.$price->method);

        $disabled = ServiceAvailability::disabledServices();
        $unavailableTypes = ServiceAvailability::unavailableTypes();

        foreach (ServiceCatalog::all() as $service => $definition) {
            $this->enabledServices[$service] = ! isset($disabled[$service]);
            $this->unavailableServices[$service] = ServiceAvailability::isUnavailable($service);
            foreach ($definition['methods'] as $method => $label) {
                $this->unavailableTypes[$service][$method] = isset($unavailableTypes[$service][$method]);
                $amount = $existing->get($service.'.'.$method)?->amount;
                $this->prices[$service][$method] = $amount === null ? '' : Money::decimal($amount);
                if (in_array($service, ServicePrice::NIN_SERVICES, true)) {
                    $this->chargeErrors[$service][$method] = (bool) $existing->get($service.'.'.$method)?->charge_errors;
                }
            }
        }
        foreach (ServiceCatalog::slips() as $service => $definition) {
            foreach ($definition['slips'] as $slip => $details) {
                $this->unavailableTypes[$service][$slip] = isset($unavailableTypes[$service][$slip]);
                $price = $existing->get($service.'.'.$slip);
                $this->slipPrices[$service][$slip] = $price === null ? '0.00' : ($price->amount === null ? '' : Money::decimal($price->amount));
            }
        }
    }

    public function save(): void
    {
        Gate::authorize('prices.edit');
        $rules = [
            'unavailableTypes' => ['required', 'array:'.implode(',', [...array_keys(ServiceCatalog::all()), ...array_keys(ServiceCatalog::slips())])],
            'unavailableServices' => ['required', 'array:'.implode(',', array_keys(ServiceCatalog::all()))],
            'enabledServices' => ['required', 'array:'.implode(',', array_keys(ServiceCatalog::all()))],
            'chargeErrors' => ['required', 'array:'.implode(',', ServicePrice::NIN_SERVICES)],
            'slipPrices' => ['required', 'array:'.implode(',', array_keys(ServiceCatalog::slips()))],
        ];

        foreach (ServiceCatalog::all() as $service => $definition) {
            $rules['enabledServices.'.$service] = ['required', 'boolean'];
            $rules['unavailableServices.'.$service] = ['required', 'boolean'];
            if (in_array($service, ServicePrice::NIN_SERVICES, true)) {
                $rules['chargeErrors.'.$service] = ['required', 'array:'.implode(',', array_keys($definition['methods']))];
            }
            $rules['unavailableTypes.'.$service] = ['required', 'array:'.implode(',', array_keys($definition['methods']))];
            foreach ($definition['methods'] as $method => $label) {
                $rules['unavailableTypes.'.$service.'.'.$method] = ['required', 'boolean'];
                $rules['prices.'.$service.'.'.$method] = ['nullable', 'string', 'regex:'.Money::INPUT_PATTERN];
                if (in_array($service, ServicePrice::NIN_SERVICES, true)) {
                    $rules['chargeErrors.'.$service.'.'.$method] = ['required', 'boolean'];
                }
            }
        }

        foreach (ServiceCatalog::slips() as $service => $definition) {
            $rules['unavailableTypes.'.$service] = ['required', 'array:'.implode(',', array_keys($definition['slips']))];
            $rules['slipPrices.'.$service] = ['required', 'array:'.implode(',', array_keys($definition['slips']))];
            foreach ($definition['slips'] as $slip => $details) {
                $rules['unavailableTypes.'.$service.'.'.$slip] = ['required', 'boolean'];
                $rules['slipPrices.'.$service.'.'.$slip] = ['nullable', 'string', 'regex:'.Money::INPUT_PATTERN];
            }
        }
        $this->validate($rules, ['regex' => 'Enter a non-negative amount with at most two decimal places.']);

        DB::transaction(function (): void {
            $changes = [];
            $availabilityChanges = [];

            foreach (ServiceCatalog::all() as $service => $definition) {
                $setting = SiteSetting::firstOrNew(['key' => ServiceAvailability::settingKey($service)]);
                $wasEnabled = $setting->value !== '1';
                $isEnabled = (bool) $this->enabledServices[$service];
                if ($wasEnabled !== $isEnabled) {
                    $setting->value = $isEnabled ? '0' : '1';
                    $setting->save();
                    $availabilityChanges[$service] = ['old_enabled' => $wasEnabled, 'new_enabled' => $isEnabled];
                }
                $unavailable = SiteSetting::firstOrNew(['key' => ServiceAvailability::unavailableSettingKey($service)]);
                $wasUnavailable = $unavailable->value === '1';
                $isUnavailable = (bool) $this->unavailableServices[$service];
                if ($wasUnavailable !== $isUnavailable) {
                    $unavailable->value = $isUnavailable ? '1' : '0';
                    $unavailable->save();
                    $availabilityChanges[$service]['old_unavailable'] = $wasUnavailable;
                    $availabilityChanges[$service]['new_unavailable'] = $isUnavailable;
                }
                foreach ($definition['methods'] as $method => $label) {
                    $typeChange = $this->saveTypeAvailability($service, $method);
                    if ($typeChange !== null) {
                        $availabilityChanges[$service]['types'][$method] = $typeChange;
                    }
                    $input = $this->prices[$service][$method] ?? '';
                    $amount = $input === '' || $input === null ? null : Money::parse($input);
                    $price = ServicePrice::firstOrNew(['service' => $service, 'method' => $method]);
                    $chargeErrors = in_array($service, ServicePrice::NIN_SERVICES, true) && (bool) $this->chargeErrors[$service][$method];

                    if ($price->amount !== $amount || (bool) $price->charge_errors !== $chargeErrors || ! $price->exists) {
                        $changes[$service.'.'.$method] = ['old_minor' => $price->amount, 'new_minor' => $amount, 'old_charge_errors' => (bool) $price->charge_errors, 'new_charge_errors' => $chargeErrors];
                        $price->amount = $amount;
                        $price->charge_errors = $chargeErrors;
                        $price->save();
                    }
                }
            }

            foreach (ServiceCatalog::slips() as $service => $definition) {
                foreach ($definition['slips'] as $slip => $details) {
                    $typeChange = $this->saveTypeAvailability($service, $slip);
                    if ($typeChange !== null) {
                        $availabilityChanges[$service]['types'][$slip] = $typeChange;
                    }
                    $input = $this->slipPrices[$service][$slip] ?? '';
                    $amount = $input === '' || $input === null ? null : Money::parse($input);
                    $price = ServicePrice::firstOrNew(['service' => $service, 'method' => $slip]);
                    if (! $price->exists || $price->amount !== $amount) {
                        $changes[$service.'.'.$slip] = ['old_minor' => $price->amount, 'new_minor' => $amount];
                        $price->amount = $amount;
                        $price->save();
                    }
                }
            }

            if ($availabilityChanges !== []) {
                AdminAudit::create(['actor_id' => Auth::id(), 'event' => 'services.availability_updated', 'details' => $availabilityChanges]);
            }

            if ($changes !== []) {
                AdminAudit::create(['actor_id' => Auth::id(), 'event' => 'prices.updated', 'details' => $changes]);
            }
        });

        session()->flash('status', 'Service prices saved. Service availability updated.');
    }

    /** @return array{old_unavailable: bool, new_unavailable: bool}|null */
    private function saveTypeAvailability(string $service, string $method): ?array
    {
        $setting = SiteSetting::firstOrNew(['key' => ServiceAvailability::unavailableSettingKey($service, $method)]);
        $wasUnavailable = $setting->value === '1';
        $isUnavailable = (bool) $this->unavailableTypes[$service][$method];
        if ($wasUnavailable === $isUnavailable) {
            return null;
        }
        $setting->value = $isUnavailable ? '1' : '0';
        $setting->save();

        return ['old_unavailable' => $wasUnavailable, 'new_unavailable' => $isUnavailable];
    }

    public function render(): View
    {
        return view('livewire.admin.prices', ['catalog' => ServiceCatalog::all(), 'slipCatalog' => ServiceCatalog::slips()]);
    }
}
