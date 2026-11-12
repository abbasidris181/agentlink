<?php

namespace Database\Factories;

use App\Models\NinVerificationRecord;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<NinVerificationRecord> */
class NinVerificationRecordFactory extends Factory
{
    public function definition(): array
    {
        $nin = fake()->numerify('###########');

        return [
            'user_id' => User::factory(),
            'wallet_transaction_id' => fn (array $attributes): int => WalletTransaction::factory()->create(['user_id' => $attributes['user_id'], 'actor_id' => $attributes['user_id'], 'service' => 'NIN V1'])->id,
            'reference' => (string) Str::ulid(),
            'request_key' => (string) Str::uuid(),
            'nin' => $nin,
            'method' => 'nin',
            'price' => 0,
            'status' => 'successful',
            'result' => ['firstName' => fake()->firstName(), 'lastName' => fake()->lastName(), 'idNumber' => $nin],
        ];
    }
}
