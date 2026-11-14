<?php

namespace App;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;

class ParroticaSettings
{
    /** @return array{url: string, user_id: string, sender_id: string, api_key: string} */
    public static function get(): array
    {
        $saved = SiteSetting::query()->where('key', 'notification_parrotica')->value('value');

        return $saved ? json_decode(Crypt::decryptString($saved), true, flags: JSON_THROW_ON_ERROR) : [
            'url' => (string) config('services.parrotica.url'),
            'user_id' => (string) config('services.parrotica.user_id'),
            'sender_id' => (string) config('services.parrotica.sender_id'),
            'api_key' => (string) config('services.parrotica.api_key'),
        ];
    }

    /** @param array<string, mixed> $settings */
    public static function validate(array $settings): void
    {
        Validator::make($settings, [
            'url' => ['required', 'url:https', 'max:2048', function (string $attribute, mixed $value, \Closure $fail): void {
                $url = parse_url($value);
                if (isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
                    $fail('Use an HTTPS base URL without credentials, query strings, or fragments.');
                }
            }],
            'user_id' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'sender_id' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! preg_match('/^(?:[0-9]{1,16}|[A-Za-z0-9 ]{1,11})$/D', $value) || trim($value) === '') {
                    $fail('Sender ID must be up to 11 letters, digits or spaces, or up to 16 digits.');
                }
            }],
            'api_key' => ['required', 'string', 'max:4096', 'not_regex:/[\r\n]/'],
        ])->validate();
    }

    /** @param array{url: string, user_id: string, sender_id: string, api_key: string} $settings */
    public static function save(array $settings): void
    {
        self::validate($settings);
        SiteSetting::query()->updateOrCreate(['key' => 'notification_parrotica'], ['value' => Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR))]);
    }
}
