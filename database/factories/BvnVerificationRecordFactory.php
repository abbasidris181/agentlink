<?php

namespace Database\Factories;

use App\Models\BvnVerificationRecord;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BvnVerificationRecord> */
class BvnVerificationRecordFactory extends Factory
{
    public function definition(): array
    {
        $bvn = fake()->numerify('###########');

        return [
            'user_id' => User::factory(),
            'wallet_transaction_id' => fn (array $attributes): int => WalletTransaction::factory()->create(['user_id' => $attributes['user_id'], 'actor_id' => $attributes['user_id'], 'service' => 'BVN'])->id,
            'reference' => (string) Str::ulid(),
            'request_key' => (string) Str::uuid(),
            'method' => 'bvn',
            'bvn' => $bvn,
            'price' => 0,
            'status' => 'successful',
            'result' => ['firstName' => fake()->firstName(), 'lastName' => fake()->lastName(), 'idNumber' => $bvn],
        ];
    }
}
