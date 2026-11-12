<?php

namespace App;

use InvalidArgumentException;

class Money
{
    public const MAX_MINOR = 99999999999;

    public const INPUT_PATTERN = '/\A(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?\z/';

    public static function parse(string $amount): int
    {
        if (preg_match(self::INPUT_PATTERN, $amount) !== 1) {
            throw new InvalidArgumentException('Enter an amount with at most two decimal places.');
        }

        return self::fromDecimal($amount);
    }

    public static function fromDecimal(string $amount): int
    {
        if (preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?\z/', $amount, $parts) !== 1) {
            throw new InvalidArgumentException('Enter a non-negative decimal amount with at most two decimal places.');
        }
        $digits = ltrim($parts[1].str_pad($parts[2] ?? '', 2, '0'), '0');
        $maximum = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new InvalidArgumentException('The amount exceeds the supported integer precision.');
        }

        return (int) $digits;
    }

    public static function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(int $minor): string
    {
        return number_format(intdiv($minor, 100)).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
