<?php

namespace Database\Factories;

use App\Models\MonielinksAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MonielinksAccount> */
class MonielinksAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(), 'client_id' => 'test-client',
            'customer_email' => fake()->unique()->safeEmail(), 'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(),
            'status' => 'active', 'account_number' => fake()->unique()->numerify('##########'),
            'account_name' => 'TEST CUSTOMER', 'bank' => 'PalmPay Bank', 'reference' => fake()->unique()->uuid(),
        ];
    }
}
