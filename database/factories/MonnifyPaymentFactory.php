<?php

namespace Database\Factories;

use App\Models\MonnifyAccount;
use App\Models\MonnifyPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MonnifyPayment> */
class MonnifyPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'monnify_account_id' => MonnifyAccount::factory(), 'mode' => 'live',
            'transaction_reference' => 'MNFY|'.Str::ulid(), 'payment_reference' => 'PAY-'.Str::ulid(),
            'charge_basis_points' => 0, 'status' => 'pending', 'event_data' => [],
        ];
    }
}
