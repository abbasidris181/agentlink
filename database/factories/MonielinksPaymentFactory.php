<?php

namespace Database\Factories;

use App\Models\MonielinksAccount;
use App\Models\MonielinksPayment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MonielinksPayment> */
class MonielinksPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'monielinks_account_id' => MonielinksAccount::factory(), 'transaction_reference' => (string) Str::ulid(),
            'reference' => (string) Str::ulid(), 'amount_paid' => 10000, 'charge_basis_points' => 200,
            'status' => 'review', 'event_data' => [],
        ];
    }
}
