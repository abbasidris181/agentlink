<?php

namespace App;

use App\Models\MonnifyAccount;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MonnifySettings
{
    /** @return array{enabled: bool, mode: string, api_key: string, secret_key: string, contract_code: string, funding_charge: string} */
    public static function get(): array
    {
        $stored = SiteSetting::query()->where('key', 'monnify')->value('value');

        return array_replace([
            'enabled' => false, 'mode' => 'sandbox', 'api_key' => '', 'secret_key' => '', 'contract_code' => '', 'funding_charge' => '0.00',
        ], $stored ? json_decode($stored, true, flags: JSON_THROW_ON_ERROR) : []);
    }

    /** @param array<string, mixed> $settings */
    public static function validate(array $settings, bool $requireCredentials = true): void
    {
        Validator::make($settings, [
            'enabled' => ['required', 'boolean'], 'mode' => ['required', 'in:sandbox,live'], 'funding_charge' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]?|100)(?:\.[0-9]{1,2})?\z/', 'numeric', 'between:0,100'],
            'api_key' => [$requireCredentials ? 'required' : 'nullable', 'string', 'max:4096', 'not_regex:/[:\r\n]/'],
            'secret_key' => [$requireCredentials ? 'required' : 'nullable', 'string', 'max:4096', 'not_regex:/[\r\n]/'],
            'contract_code' => [$requireCredentials ? 'required' : 'nullable', 'string', 'max:100', 'not_regex:/\s/'],
        ], [
            'api_key.required' => 'Enter the Monnify API key.', 'secret_key.required' => 'Enter the Monnify secret key.', 'contract_code.required' => 'Enter the Monnify contract code.',
        ])->validate();
    }

    /** @param array{enabled: bool, mode: string, api_key: string, secret_key: string, contract_code: string, funding_charge: string} $settings */
    public static function save(array $settings): void
    {
        self::validate($settings, $settings['enabled']);
        DB::transaction(function () use ($settings): void {
            SiteSetting::query()->where('key', 'monnify')->lockForUpdate()->first();
            $old = self::get();
            $hasLiveAccounts = $old['mode'] === 'live' && MonnifyAccount::query()->where('mode', 'live')->where('contract_code', $old['contract_code'])->where('status', '!=', 'failed')->exists();
            if ($hasLiveAccounts) {
                self::validate($settings);
            }
            if ($hasLiveAccounts && ($settings['mode'] !== 'live' || $settings['contract_code'] !== $old['contract_code'])) {
                throw ValidationException::withMessages(['contract_code' => 'Existing live reserved accounts belong to this contract. Keep their mode and contract code when updating credentials.']);
            }
            SiteSetting::query()->updateOrCreate(['key' => 'monnify'], ['value' => json_encode([...$settings, 'funding_charge' => Money::decimal(Money::parse($settings['funding_charge']))], JSON_THROW_ON_ERROR)]);
        });
    }

    /** @param array<string, mixed> $settings */
    public static function baseUrl(array $settings): string
    {
        return $settings['mode'] === 'live' ? 'https://api.monnify.com' : 'https://sandbox.monnify.com';
    }
}
