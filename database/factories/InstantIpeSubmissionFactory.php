<?php

namespace Database\Factories;

use App\Models\InstantIpeSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<InstantIpeSubmission> */
class InstantIpeSubmissionFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'request_key' => (string) Str::uuid(), 'batch_id' => null, 'mode' => 'single'];
    }
}
