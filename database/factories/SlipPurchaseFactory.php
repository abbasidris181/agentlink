<?php

namespace Database\Factories;

use App\Models\NinVerificationRecord;
use App\Models\SlipPurchase;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SlipPurchase> */
class SlipPurchaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nin_verification_record_id' => NinVerificationRecord::factory(),
            'user_id' => fn (array $attributes): int => NinVerificationRecord::findOrFail($attributes['nin_verification_record_id'])->user_id,
            'wallet_transaction_id' => fn (array $attributes): int => WalletTransaction::factory()->create(['user_id' => $attributes['user_id'], 'actor_id' => $attributes['user_id'], 'service' => 'NIN slip', 'type' => 'service_debit'])->id,
            'slip' => 'regular',
            'price' => 1000,
        ];
    }
}
