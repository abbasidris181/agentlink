<?php

namespace Database\Factories;

use App\Models\PersonalizationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PersonalizationRequest> */
class PersonalizationRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reference' => (string) Str::ulid(),
            'tracking_id' => strtoupper(fake()->bothify('?????##########')),
            'tracking_hash' => fn (array $attributes): string => hash_hmac('sha256', $attributes['tracking_id'], config('app.key')),
            'status' => 'in_progress',
            'provider_id' => (string) fake()->numberBetween(1, 999999),
        ];
    }
}
