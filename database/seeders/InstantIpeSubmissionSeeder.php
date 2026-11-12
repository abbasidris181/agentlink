<?php

namespace Database\Seeders;

use App\Models\InstantIpeSubmission;
use Illuminate\Database\Seeder;

class InstantIpeSubmissionSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            InstantIpeSubmission::factory()->count(3)->create();
        }
    }
}
