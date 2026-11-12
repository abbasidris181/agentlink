<?php

namespace Database\Factories;

use App\Models\AdminAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdminAudit> */
class AdminAuditFactory extends Factory
{
    public function definition(): array
    {
        return ['actor_id' => User::factory()->administrator(), 'user_id' => null, 'event' => 'user.updated', 'details' => ['fields' => ['name']]];
    }
}
