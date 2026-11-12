<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WalletTransaction> */
class WalletTransactionFactory extends Factory
{
    public function definition(): array
    {
        return ['reference' => (string) Str::ulid(), 'request_key' => (string) Str::uuid(), 'user_id' => User::factory(), 'actor_id' => User::factory()->administrator(), 'service' => 'Wallet credit', 'type' => 'credit', 'status' => 'completed', 'amount' => 1000, 'old_balance' => 0, 'new_balance' => 1000, 'reason' => 'Test wallet adjustment'];
    }
}
