<?php

namespace Database\Factories;

use App\Models\InstantIpeRequest;
use App\Models\InstantIpeSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<InstantIpeRequest> */
class InstantIpeRequestFactory extends Factory
{
    public function definition(): array
    {
        $trackingId = 'ABC'.fake()->unique()->numerify('############');

        return ['instant_ipe_submission_id' => InstantIpeSubmission::factory(), 'user_id' => fn (array $attributes): int => InstantIpeSubmission::query()->findOrFail($attributes['instant_ipe_submission_id'])->user_id, 'reference' => (string) Str::ulid(), 'tracking_id' => $trackingId, 'active_tracking_id' => null, 'price' => 0, 'status' => 'queued'];
    }
}
