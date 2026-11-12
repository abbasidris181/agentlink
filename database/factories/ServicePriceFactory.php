<?php

namespace Database\Factories;

use App\Models\ServicePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServicePrice> */
class ServicePriceFactory extends Factory
{
    public function definition(): array
    {
        return ['service' => 'nin-v1', 'method' => 'nin', 'amount' => 15000];
    }
}
