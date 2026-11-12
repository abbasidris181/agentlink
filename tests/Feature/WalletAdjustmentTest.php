<?php

namespace Tests\Feature;

use App\Livewire\Admin\Users;
use App\Models\AdminAudit;
use App\Models\User;
use App\Money;
use App\WalletAdjustment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WalletAdjustmentTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['credit', 10101])]
    #[TestWith(['debit', 9899])]
    public function test_wallet_adjustments_record_exact_balances_and_an_audit(string $direction, int $expectedBalance): void
    {
        $admin = User::factory()->administrator()->create();
        $user = User::factory()->create(['wallet_balance' => 10000]);

        $transaction = app(WalletAdjustment::class)->apply($admin, $user->id, $direction, '1.01', 'Account correction', (string) Str::uuid());

        $this->assertSame($expectedBalance, $user->fresh()->wallet_balance);
        $this->assertSame(101, $transaction->amount);
        $this->assertSame(10000, $transaction->old_balance);
        $this->assertSame($expectedBalance, $transaction->new_balance);
        $this->assertSame($admin->id, $transaction->actor_id);
        $this->assertDatabaseHas('admin_audits', ['actor_id' => $admin->id, 'user_id' => $user->id, 'event' => 'wallet.'.$direction]);
    }

    #[TestWith(['-1'])]
    #[TestWith(['0'])]
    #[TestWith(['0.00'])]
    #[TestWith(['1.001'])]
    #[TestWith(['1e3'])]
    #[TestWith(['not money'])]
    public function test_invalid_adjustment_amounts_do_not_change_the_balance(string $amount): void
    {
        $admin = User::factory()->administrator()->create();
        $user = User::factory()->create(['wallet_balance' => 50000]);
        $this->actingAs($admin);

        Livewire::test(Users::class, ['userId' => $user->id])->set('amount', $amount)
            ->set('reason', 'Account correction')->call('adjustWallet')->assertHasErrors(['amount']);

        $this->assertSame(50000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('admin_audits', 0);
    }

    public function test_debits_cannot_overdraw_the_wallet(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $user = User::factory()->create(['wallet_balance' => 100]);

        Livewire::test(Users::class, ['userId' => $user->id])->set('direction', 'debit')
            ->set('amount', '1.01')->set('reason', 'Debit correction')->call('adjustWallet')
            ->assertHasErrors(['amount'])->assertSee('The debit exceeds the available wallet balance.');

        $this->assertSame(100, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_credit_cannot_exceed_the_supported_balance(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $user = User::factory()->create(['wallet_balance' => Money::MAX_MINOR]);

        Livewire::test(Users::class, ['userId' => $user->id])->set('amount', '0.01')
            ->set('reason', 'Balance correction')->call('adjustWallet')->assertHasErrors(['amount']);

        $this->assertSame(Money::MAX_MINOR, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_replaying_an_adjustment_does_not_change_the_balance_twice(): void
    {
        $admin = User::factory()->administrator()->create();
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        $service = app(WalletAdjustment::class);

        $first = $service->apply($admin, $user->id, 'credit', '50.25', 'Opening balance', $key);
        $second = $service->apply($admin, $user->id, 'credit', '50.25', 'Opening balance', $key);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(5025, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('admin_audits', 1);
    }

    public function test_reusing_a_reference_for_different_details_is_rejected(): void
    {
        $admin = User::factory()->administrator()->create();
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        $service = app(WalletAdjustment::class);
        $service->apply($admin, $user->id, 'credit', '5.00', 'Opening balance', $key);

        try {
            $service->apply($admin, $user->id, 'credit', '10.00', 'Opening balance', $key);
            $this->fail('The changed adjustment should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }

        $this->assertSame(500, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_regular_users_cannot_use_the_wallet_adjustment_service(): void
    {
        $user = User::factory()->create();

        try {
            app(WalletAdjustment::class)->apply($user, $user->id, 'credit', '100', 'Unauthorized credit', (string) Str::uuid());
            $this->fail('A customer must not be allowed to adjust wallets.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(0, $user->fresh()->wallet_balance);
        }

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_adjustments_require_a_reason_and_allow_only_credit_or_debit(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $user = User::factory()->create();

        Livewire::test(Users::class, ['userId' => $user->id])->set('direction', 'transfer')
            ->set('amount', '100')->set('reason', ' ')->call('adjustWallet')->assertHasErrors(['reason', 'direction']);

        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_balance_and_transaction_history_are_visible_only_to_the_owner(): void
    {
        $admin = User::factory()->administrator()->create();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $transaction = app(WalletAdjustment::class)->apply($admin, $owner->id, 'credit', '250.75', 'Opening credit', (string) Str::uuid());

        $this->actingAs($owner);
        $this->get(route('dashboard'))->assertOk()->assertSee('250.75');
        $this->get(route('transactions'))->assertOk()->assertSee($transaction->reference)->assertSee('250.75');
        $this->actingAs($other);
        $this->get(route('transactions'))->assertOk()->assertDontSee($transaction->reference);
    }

    public function test_ledger_and_balance_roll_back_together_when_recording_an_audit_fails(): void
    {
        $admin = User::factory()->administrator()->create();
        $user = User::factory()->create();
        AdminAudit::creating(function (): void {
            throw new \RuntimeException('Audit unavailable');
        });

        try {
            app(WalletAdjustment::class)->apply($admin, $user->id, 'credit', '100', 'Opening credit', (string) Str::uuid());
            $this->fail('The adjustment should have rolled back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            AdminAudit::flushEventListeners();
        }

        $this->assertSame(0, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }
}
