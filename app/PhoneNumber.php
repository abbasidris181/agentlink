<?php

namespace App;

class PhoneNumber
{
    public static function normalize(?string $input): ?string
    {
        $input = trim($input ?? '');
        if (! preg_match('/\A\+?[0-9 ()-]{7,30}\z/', $input)) {
            return null;
        }
        $digits = preg_replace('/[^0-9]/', '', $input);
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = '234'.substr($digits, 1);
        } elseif (strlen($digits) === 10 && ! str_starts_with($digits, '0')) {
            $digits = '234'.$digits;
        }

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null;
    }
}
