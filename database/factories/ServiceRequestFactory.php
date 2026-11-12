<?php

namespace Database\Factories;

use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ServiceRequest> */
class ServiceRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => (string) Str::ulid(), 'request_key' => (string) Str::uuid(), 'user_id' => User::factory(), 'service' => 'validations', 'method' => 'nin', 'payload' => ['nin' => fake()->numerify('###########')], 'status' => 'pending', 'quoted_price' => null];
    }
}
