<?php

namespace App;

use App\Models\SiteSetting;
use Illuminate\Validation\ValidationException;

class ServiceAvailability
{
    public static function canonical(string $service): string
    {
        return $service === 'bvn retrival' ? 'bvn-retrieval' : $service;
    }

    public static function settingKey(string $service): string
    {
        return 'service_disabled.'.self::canonical($service);
    }

    public static function unavailableSettingKey(string $service, ?string $method = null): string
    {
        return $method === null
            ? 'service_unavailable.'.self::canonical($service)
            : 'service_type_unavailable.'.self::canonical($service).'.'.$method;
    }

    /** @return array<string, array<string, bool>> */
    public static function unavailableTypes(): array
    {
        $types = [];
        foreach (SiteSetting::query()->where('key', 'like', 'service_type_unavailable.%')->where('value', '1')->pluck('key') as $key) {
            $parts = explode('.', $key, 3);
            if (count($parts) === 3) {
                $types[$parts[1]][$parts[2]] = true;
            }
        }

        return $types;
    }

    public static function isUnavailable(string $service): bool
    {
        return SiteSetting::enabled(self::unavailableSettingKey($service));
    }

    /** @param string|list<string>|null $methods */
    public static function canSubmit(string $service, string|array|null $methods = null): bool
    {
        if (! self::isEnabled($service) || self::isUnavailable($service)) {
            return false;
        }
        foreach ((array) $methods as $method) {
            if (SiteSetting::enabled(self::unavailableSettingKey($service, $method))) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, bool> */
    public static function unavailableServices(): array
    {
        $unavailable = SiteSetting::query()->whereIn('key', array_map(self::unavailableSettingKey(...), array_keys(ServiceCatalog::all())))
            ->where('value', '1')->pluck('key')->mapWithKeys(fn (string $key): array => [substr($key, strlen('service_unavailable.')) => true])->all();
        if (isset($unavailable['bvn-retrieval'])) {
            $unavailable['bvn retrival'] = true;
        }

        return $unavailable;
    }

    public static function isEnabled(string $service): bool
    {
        $service = self::canonical($service);

        return array_key_exists($service, ServiceCatalog::all()) && ! SiteSetting::enabled(self::settingKey($service));
    }

    /** @return array<string, bool> */
    public static function disabledServices(): array
    {
        $keys = array_map(self::settingKey(...), array_keys(ServiceCatalog::all()));
        $disabled = SiteSetting::query()->whereIn('key', $keys)->where('value', '1')->pluck('key')->mapWithKeys(
            fn (string $key): array => [substr($key, strlen('service_disabled.')) => true],
        )->all();
        if (isset($disabled['bvn-retrieval'])) {
            $disabled['bvn retrival'] = true;
        }

        return $disabled;
    }

    public static function assertEnabled(string $service): void
    {
        abort_unless(self::isEnabled($service), 503, 'This service is temporarily unavailable. Please try again later.');
    }

    /** @param string|list<string>|null $methods */
    public static function validateSubmission(string $service, string|array|null $methods = null): void
    {
        if (! self::canSubmit($service, $methods)) {
            throw ValidationException::withMessages(['serviceAvailability' => 'This service is temporarily unavailable. Please try again later.']);
        }
    }

    public static function forRoute(?string $route): ?string
    {
        return [
            'nin.v1' => 'nin-v1', 'nin.v2' => 'nin-v2', 'nin.v3' => 'nin-v3',
            'bvn.verify' => 'bvn-verification', 'bvn.retrieval' => 'bvn-retrieval',
            'ipe.clearing' => 'ipe-clearing', 'ipe.mod' => 'mod-ipe', 'ipe.instant' => 'instant-ipe',
            'validations' => 'validations', 'delinking' => 'delinking',
            'bvn.onboarding' => 'bvn-license-onboarding',
            'demographics.search' => 'demographics-search',
            'personalization' => 'personalization', 'nin.modification' => 'nin-modification',
            'nin.modification.form' => 'nin-modification', 'bvn.modification' => 'bvn-modification',
        ][$route] ?? null;
    }
}
