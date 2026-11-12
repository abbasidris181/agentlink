<?php

namespace Database\Seeders;

use App\Models\InstantIpeRequest;
use Illuminate\Database\Seeder;

class InstantIpeRequestSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            InstantIpeRequest::factory()->count(3)->create();
        }
    }
}
