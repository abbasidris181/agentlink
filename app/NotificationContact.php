<?php

namespace App;

use App\Models\User;

class NotificationContact
{
    public static function normalize(?string $value, string $channel): ?string
    {
        $value = trim($value ?? '');
        if ($channel === 'email') {
            return filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_strtolower($value) : null;
        }

        if (! preg_match('/^[+0-9\s()\-]+$/D', $value)) {
            return null;
        }
        $value = preg_replace('/[\s()\-]+/', '', $value);
        if (preg_match('/^0[789][01][0-9]{8}$/D', $value)) {
            $value = '+234'.substr($value, 1);
        } elseif (preg_match('/^0[0-9]{10}$/D', $value)) {
            $value = '+234'.substr($value, 1);
        } elseif (preg_match('/^[1-9][0-9]{7,14}$/D', $value)) {
            $value = '+'.$value;
        }

        if (! preg_match('/^\+[1-9][0-9]{7,14}$/D', $value)) {
            return null;
        }
        if (str_starts_with($value, '+234') && ! preg_match('/^\+234[1-9][0-9]{9}$/D', $value)) {
            return null;
        }

        return $value;
    }

    public static function forUser(User $user, string $channel): ?string
    {
        return self::normalize($channel === 'email' ? $user->email : $user->phone, $channel);
    }
}
