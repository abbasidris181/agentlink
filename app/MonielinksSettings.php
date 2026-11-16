<?php

namespace App;

use App\Models\MonielinksAccount;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MonielinksSettings
{
    /** @return array{enabled: bool, client_id: string, secret_key: string, webhook_secret: string, funding_charge: string} */
    public static function get(): array
    {
        $stored = SiteSetting::query()->where('key', 'monielinks')->value('value');

        return array_replace([
            'enabled' => false, 'client_id' => '', 'secret_key' => '', 'webhook_secret' => '', 'funding_charge' => '0.00',
        ], $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : []);
    }

    /** @param array<string, mixed> $settings */
    public static function validate(array $settings, bool $requireCredentials = true): void
    {
        Validator::make($settings, [
            'enabled' => ['required', 'boolean'],
            'client_id' => [$requireCredentials ? 'required' : 'nullable', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'secret_key' => [$requireCredentials ? 'required' : 'nullable', 'string', 'max:4096', 'not_regex:/[\r\n]/'],
            'webhook_secret' => ['nullable', 'string', 'max:4096', 'not_regex:/[\r\n]/'],
            'funding_charge' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]?|100)(?:\.[0-9]{1,2})?\z/', 'numeric', 'between:0,100'],
        ])->validate();
    }

    /** @param array{enabled: bool, client_id: string, secret_key: string, webhook_secret: string, funding_charge: string} $settings */
    public static function save(array $settings): void
    {
        self::validate($settings, $settings['enabled']);
        DB::transaction(function () use ($settings): void {
            SiteSetting::query()->where('key', 'monielinks')->lockForUpdate()->first();
            $old = self::get();
            if (MonielinksAccount::query()->where('status', '!=', 'failed')->exists()) {
                self::validate($settings);
                if ($settings['client_id'] !== $old['client_id']) {
                    throw ValidationException::withMessages(['client_id' => 'Existing virtual accounts belong to this client ID. Keep it when updating credentials.']);
                }
                if ($old['webhook_secret'] !== '' && $settings['webhook_secret'] === '') {
                    throw ValidationException::withMessages(['webhook_secret' => 'Keep a signing secret configured for existing virtual accounts.']);
                }
            }
            SiteSetting::query()->updateOrCreate(['key' => 'monielinks'], ['value' => json_encode([
                ...$settings, 'funding_charge' => Money::decimal(Money::parse($settings['funding_charge'])),
            ], JSON_THROW_ON_ERROR)]);
        });
    }
}
