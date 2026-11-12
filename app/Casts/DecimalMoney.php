<?php

namespace App\Casts;

use App\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Store decimal amounts while keeping integer minor units for exact arithmetic.
 *
 * @implements CastsAttributes<int, int>
 */
class DecimalMoney implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return $value === null ? null : Money::fromDecimal((string) $value);
    }

    /** @param array<string, mixed> $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Money calculations require non-negative integer minor units.');
        }

        return Money::decimal($value);
    }
}
