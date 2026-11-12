<?php

namespace Database\Seeders;

use App\Models\ServicePrice;
use App\ServiceCatalog;
use Illuminate\Database\Seeder;

class ServicePriceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ServiceCatalog::all() as $service => $definition) {
            foreach ($definition['methods'] as $method => $label) {
                ServicePrice::firstOrCreate(['service' => $service, 'method' => $method], ['amount' => null]);
            }
        }
    }
}
