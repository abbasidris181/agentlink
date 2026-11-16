<?php

namespace Database\Factories;

use App\Models\MonnifyAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MonnifyAccount> */
class MonnifyAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(), 'mode' => 'live', 'contract_code' => '1234567890',
            'reference' => 'WALLET-'.Str::ulid(), 'customer_email' => fake()->safeEmail(), 'customer_name' => fake()->name(),
            'reservation_reference' => (string) Str::ulid(), 'status' => 'active',
            'accounts' => [['bankCode' => '50515', 'bankName' => 'Moniepoint Microfinance Bank', 'accountNumber' => fake()->unique()->numerify('##########'), 'accountName' => 'TEST CUSTOMER']],
        ];
    }
}
