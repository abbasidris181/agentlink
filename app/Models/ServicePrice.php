<?php

namespace App\Models;

use App\Casts\DecimalMoney;
use App\Money;
use Database\Factories\ServicePriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['service', 'method', 'amount', 'charge_errors'])]
class ServicePrice extends Model
{
    public const NIN_SERVICES = ['nin-v1', 'nin-v2', 'nin-v3'];

    /** @use HasFactory<ServicePriceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['amount' => DecimalMoney::class, 'charge_errors' => 'boolean'];
    }

    /** @param list<string> $methods */
    public static function totalFor(string $service, array $methods): ?int
    {
        if ($methods === []) {
            return null;
        }
        $total = 0;
        foreach (array_unique($methods) as $method) {
            $amount = self::amountFor($service, $method);
            if ($amount === null || $total + $amount > Money::MAX_MINOR) {
                return null;
            }
            $total += $amount;
        }

        return $total;
    }

    public static function amountFor(string $service, string $method): ?int
    {
        return static::query()->where('service', $service)->where('method', $method)->value('amount');
    }
}
