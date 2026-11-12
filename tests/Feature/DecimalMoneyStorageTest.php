<?php

namespace Tests\Feature;

use App\Livewire\Admin\Overview;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Money;
use App\RefundServiceRequest;
use App\SubmitServiceRequest;
use App\WalletTransfer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class DecimalMoneyStorageTest extends TestCase
{
    use DatabaseMigrations;

    public function test_decimal_columns_store_exact_amounts_and_preserve_legacy_values_on_conversion(): void
    {
        $owner = User::factory()->create(['wallet_balance' => 37501]);
        $price = ServicePrice::factory()->create(['service' => 'validations', 'method' => 'nin', 'amount' => 12501]);
        $unset = ServicePrice::factory()->create(['service' => 'delinking', 'method' => 'nin', 'amount' => null]);
        $free = ServicePrice::factory()->create(['service' => 'mod-ipe', 'method' => 'tracking', 'amount' => 0]);
        $request = ServiceRequest::factory()->create(['user_id' => $owner->id, 'quoted_price' => 12501]);
        $transaction = WalletTransaction::factory()->create(['user_id' => $owner->id, 'actor_id' => $owner->id, 'service_request_id' => $request->id, 'amount' => 12501, 'old_balance' => 50002, 'new_balance' => 37501]);
        $maximum = User::factory()->create(['wallet_balance' => Money::MAX_MINOR]);
        $migration = require database_path('migrations/2026_10_24_105750_convert_money_columns_to_decimal.php');
        $migration->down();
        try {
            $this->assertDatabaseHas('users', ['id' => $owner->id, 'wallet_balance' => 37501]);
            $this->assertDatabaseHas('wallet_transactions', ['id' => $transaction->id, 'amount' => 12501, 'old_balance' => 50002, 'new_balance' => 37501]);
            $this->assertDatabaseHas('service_prices', ['id' => $price->id, 'amount' => 12501]);
            $this->assertDatabaseHas('service_prices', ['id' => $unset->id, 'amount' => null]);
            $this->assertDatabaseHas('service_prices', ['id' => $free->id, 'amount' => 0]);
            $this->assertDatabaseHas('service_requests', ['id' => $request->id, 'quoted_price' => 12501]);
            $this->assertDatabaseHas('users', ['id' => $maximum->id, 'wallet_balance' => 99999999999]);
        } finally {
            $migration->up();
        }
        $this->assertSame('375.01', DB::table('users')->where('id', $owner->id)->value('wallet_balance'));
        $this->assertSame('125.01', DB::table('service_prices')->where('id', $price->id)->value('amount'));
        $this->assertSame('125.01', DB::table('service_requests')->where('id', $request->id)->value('quoted_price'));
        $this->assertDatabaseHas('wallet_transactions', ['id' => $transaction->id, 'amount' => '125.01', 'old_balance' => '500.02', 'new_balance' => '375.01']);
        $this->assertSame('999999999.99', DB::table('users')->where('id', $maximum->id)->value('wallet_balance'));
        $this->assertNull($unset->fresh()->amount);
        $this->assertSame(0, $free->fresh()->amount);
        $this->assertSame(37501, $owner->fresh()->wallet_balance);
        foreach (['users' => ['wallet_balance'], 'wallet_transactions' => ['amount', 'old_balance', 'new_balance'], 'service_prices' => ['amount'], 'service_requests' => ['quoted_price']] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame('decimal', Schema::getColumnType($table, $column));
            }
        }
    }

    public function test_fractional_transfers_are_stored_as_decimals_without_losing_cents(): void
    {
        $sender = User::factory()->agent()->create(['wallet_balance' => 203]);
        $recipient = User::factory()->create();
        $transfer = app(WalletTransfer::class)->send($sender, $recipient->id, '1.01', (string) Str::uuid());
        $this->assertSame('1.02', DB::table('users')->where('id', $sender->id)->value('wallet_balance'));
        $this->assertSame('1.01', DB::table('users')->where('id', $recipient->id)->value('wallet_balance'));
        $this->assertDatabaseHas('wallet_transactions', ['id' => $transfer->id, 'amount' => '1.01', 'old_balance' => '2.03', 'new_balance' => '1.02']);
        $this->assertSame(102, $sender->fresh()->wallet_balance);
    }

    public function test_a_one_cent_service_charge_is_refunded_as_one_cent(): void
    {
        $owner = User::factory()->create(['wallet_balance' => 203]);
        ServicePrice::factory()->create(['service' => 'validations', 'method' => 'nin', 'amount' => 1]);
        $record = app(SubmitServiceRequest::class)->create($owner, 'validations', 'nin', ['nin' => '01234567890'], (string) Str::uuid());
        $this->assertSame('2.02', DB::table('users')->where('id', $owner->id)->value('wallet_balance'));
        $refund = app(RefundServiceRequest::class)->refund(User::factory()->administrator()->create(), $record->id);
        $this->assertDatabaseHas('wallet_transactions', ['id' => $refund->id, 'amount' => '0.01', 'old_balance' => '2.02', 'new_balance' => '2.03']);
        $this->assertSame('2.03', DB::table('users')->where('id', $owner->id)->value('wallet_balance'));
    }

    public function test_admin_total_balances_preserve_cents_and_support_totals_above_a_single_wallet_limit(): void
    {
        User::factory()->count(2)->create(['wallet_balance' => Money::MAX_MINOR]);
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Overview::class)->assertSee('1,999,999,999.98');
    }
}
