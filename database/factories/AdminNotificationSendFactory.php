<?php

namespace Database\Factories;

use App\Models\AdminNotificationSend;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdminNotificationSend> */
class AdminNotificationSendFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'channel' => 'email', 'status' => 'draft',
            'payload' => ['subject' => 'Account update', 'message' => 'Test notification'],
            'recipients' => [['contact' => fake()->safeEmail(), 'users' => [], 'status' => 'pending']]];
    }
}
