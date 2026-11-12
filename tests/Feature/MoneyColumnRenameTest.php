<?php

namespace Tests\Feature;

use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MoneyColumnRenameTest extends TestCase
{
    use DatabaseMigrations;

    public function test_renaming_and_reversing_money_columns_preserves_existing_amounts_and_null_prices(): void
    {
        $owner = User::factory()->create(['wallet_balance' => 37500]);
        $price = ServicePrice::factory()->create(['service' => 'validations', 'method' => 'nin', 'amount' => 12500]);
        $unsetPrice = ServicePrice::factory()->create(['service' => 'delinking', 'method' => 'nin', 'amount' => null]);
        $freePrice = ServicePrice::factory()->create(['service' => 'mod-ipe', 'method' => 'tracking', 'amount' => 0]);
        $request = ServiceRequest::factory()->create(['user_id' => $owner->id, 'quoted_price' => 12500]);
        $transaction = WalletTransaction::factory()->create(['user_id' => $owner->id, 'actor_id' => $owner->id, 'service_request_id' => $request->id, 'amount' => 12500, 'old_balance' => 50000, 'new_balance' => 37500]);
        $migration = require database_path('migrations/2026_10_24_105152_rename_minor_suffix_columns.php');
        $migration->down();
        try {
            $this->assertDatabaseHas('users', ['id' => $owner->id, 'wallet_balance_minor' => '375.00']);
            $this->assertDatabaseHas('wallet_transactions', ['id' => $transaction->id, 'amount_minor' => '125.00', 'old_balance_minor' => '500.00', 'new_balance_minor' => '375.00']);
            $this->assertDatabaseHas('service_prices', ['id' => $price->id, 'amount_minor' => '125.00']);
            $this->assertDatabaseHas('service_prices', ['id' => $unsetPrice->id, 'amount_minor' => null]);
            $this->assertDatabaseHas('service_prices', ['id' => $freePrice->id, 'amount_minor' => '0.00']);
            $this->assertDatabaseHas('service_requests', ['id' => $request->id, 'quoted_price_minor' => '125.00']);
        } finally {
            $migration->up();
        }
        $this->assertSame(37500, $owner->fresh()->wallet_balance);
        $this->assertSame(12500, $price->fresh()->amount);
        $this->assertNull($unsetPrice->fresh()->amount);
        $this->assertSame(0, $freePrice->fresh()->amount);
        $this->assertSame(12500, $request->fresh()->quoted_price);
        $this->assertSame(12500, $transaction->fresh()->amount);
        $this->assertSame(50000, $transaction->fresh()->old_balance);
        $this->assertSame(37500, $transaction->fresh()->new_balance);
        foreach (['users', 'wallet_transactions', 'service_prices', 'service_requests'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertFalse(str_ends_with($column, '_minor'), $table.'.'.$column.' retains the old suffix.');
            }
        }
        $this->assertSame(1, DB::table('wallet_transactions')->count());
        $this->assertSame(1, DB::table('service_requests')->count());
    }
}
