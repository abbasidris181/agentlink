<?php

namespace Tests\Feature;

use App\AdminAccess;
use App\ChangeUserAccess;
use App\Livewire\Admin\Overview;
use App\Livewire\Admin\Prices;
use App\Livewire\Admin\Settings;
use App\Livewire\Admin\Users;
use App\Livewire\WalletTransferForm;
use App\Models\AdminAudit;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Money;
use App\WalletAdjustment;
use App\WalletTransfer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RolesAndTransfersTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['users', false, false])]
    #[TestWith(['agent', false, true])]
    #[TestWith(['staff', true, false])]
    #[TestWith(['super_admin', true, true])]
    public function test_role_access_matrix(string $role, bool $admin, bool $transfer): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);
        $this->assertSame($admin, Gate::allows('access-admin'));
        $this->assertSame($transfer, Gate::allows('wallet.transfer'));
        $this->get(route('admin.dashboard'))->assertStatus($admin ? 200 : 403);
        $this->get(route('wallet.transfer'))->assertStatus($transfer ? 200 : 403);
        $user->is_active = false;
        $user->save();
        $this->assertFalse(Gate::allows('access-admin'));
        $this->assertFalse(Gate::allows('wallet.transfer'));
    }

    public function test_staff_sections_are_denied_by_default_and_granted_individually(): void
    {
        $staff = User::factory()->staff(['prices.view'])->create();
        $this->actingAs($staff);
        $this->get(route('admin.prices'))->assertOk()->assertSee('disabled');
        $this->get(route('admin.settings'))->assertForbidden();
        $this->get(route('admin.users'))->assertForbidden();
        Livewire::test(Settings::class)->assertForbidden();
        Livewire::test(Prices::class)->call('save')->assertForbidden();
        $staff->staff_permissions = AdminAccess::withDependencies(['prices.edit']);
        $staff->save();
        Livewire::test(Prices::class)->set('prices.nin-v1.nin', '12.50')->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('service_prices', ['service' => 'nin-v1', 'method' => 'nin', 'amount' => '12.50']);
    }

    public function test_staff_hidden_fields_are_absent_from_views_and_public_state(): void
    {
        $staff = User::factory()->staff(['users.view', 'dashboard.view'])->create();
        $customer = User::factory()->create(['wallet_balance' => 123456]);
        $this->actingAs($staff);
        Livewire::test(Users::class, ['userId' => $customer->id])->assertSet('email', '')->assertSet('phone', '')
            ->assertDontSee($customer->email)->assertDontSee($customer->phone)->assertDontSee('1,234.56')
            ->assertDontSee('Latest 20 transactions')->assertDontSee('Recent account activity')->assertDontSee('Save access');
        Livewire::test(Users::class)->assertDontSee($customer->email)->assertDontSee('Balance');
        Livewire::test(Overview::class)->assertDontSee('Wallet balances')->assertDontSee($customer->email)->assertDontSee('Recent admin activity');
    }

    #[TestWith(['saveProfile'])]
    #[TestWith(['resetPassword'])]
    #[TestWith(['toggleStatus'])]
    #[TestWith(['adjustWallet'])]
    #[TestWith(['saveAccess'])]
    public function test_view_only_staff_cannot_call_mutations(string $action): void
    {
        $this->actingAs(User::factory()->staff(['users.view'])->create());
        $user = User::factory()->create();
        Livewire::test(Users::class, ['userId' => $user->id])->call($action)->assertForbidden();
        $this->assertSame('users', $user->fresh()->role);
        $this->assertSame(0, $user->fresh()->wallet_balance);
    }

    #[TestWith(['saveProfile'])]
    #[TestWith(['resetPassword'])]
    #[TestWith(['toggleStatus'])]
    public function test_staff_cannot_manage_administrator_accounts(string $action): void
    {
        $this->actingAs(User::factory()->staff(['users.edit', 'users.password', 'users.status'])->create());
        $admin = User::factory()->administrator()->create();
        Livewire::test(Users::class, ['userId' => $admin->id])->call($action)->assertForbidden();
    }

    public function test_super_admin_can_assign_roles_and_selected_permissions(): void
    {
        $admin = User::factory()->administrator()->create();
        $user = User::factory()->create();
        $this->actingAs($admin);
        Livewire::test(Users::class, ['userId' => $user->id])->set('role', 'staff')
            ->set('staffPermissions', ['users.edit', 'wallet.transfer'])->call('saveAccess')->assertHasNoErrors();
        $this->assertSame('staff', $user->fresh()->role);
        $this->assertEqualsCanonicalizing(['users.edit', 'users.view', 'users.contact', 'wallet.transfer'], $user->fresh()->staff_permissions);
        $this->assertDatabaseHas('admin_audits', ['actor_id' => $admin->id, 'user_id' => $user->id, 'event' => 'user.access_updated']);
        Livewire::test(Users::class, ['userId' => $user->id])->set('staffPermissions', ['wallet.adjust'])->call('saveAccess')->assertHasErrors('permissions.0');
        Livewire::test(Users::class, ['userId' => $admin->id])->set('role', 'users')->call('saveAccess')->assertHasErrors('role');
        $this->assertSame('super_admin', $admin->fresh()->role);
        app(ChangeUserAccess::class)->apply($admin, $user->id, 'agent');
        $this->assertSame([], $user->fresh()->staff_permissions);
    }

    public function test_permissions_are_rechecked_on_existing_components(): void
    {
        $staff = User::factory()->staff(['prices.edit', 'users.edit'])->create();
        $customer = User::factory()->create();
        $this->actingAs($staff);
        $prices = Livewire::test(Prices::class);
        $users = Livewire::test(Users::class, ['userId' => $customer->id]);
        $staff->staff_permissions = ['prices.view', 'users.view'];
        $staff->save();
        $prices->call('save')->assertForbidden();
        $users->call('$refresh')->assertSet('email', '')->assertSet('phone', '')->assertDontSee($customer->email);
    }

    public function test_privileged_fields_cannot_be_mass_assigned(): void
    {
        $user = User::factory()->create();
        $user->fill(['role' => 'super_admin', 'staff_permissions' => ['wallet.transfer'], 'normalized_phone' => '2348000000000'])->save();
        $this->assertSame('users', $user->fresh()->role);
        $this->assertNull($user->fresh()->staff_permissions);
        $this->assertNotSame('2348000000000', $user->fresh()->normalized_phone);
    }

    public function test_agents_and_authorized_staff_send_from_their_own_balance_with_linked_history(): void
    {
        foreach (['agent', 'staff', 'super_admin'] as $role) {
            $sender = User::factory()->create(['role' => $role, 'staff_permissions' => ['wallet.transfer'], 'wallet_balance' => 10000]);
            $recipient = User::factory()->create(['wallet_balance' => 100]);
            $entry = app(WalletTransfer::class)->send($sender, $recipient->id, '25.75', (string) Str::uuid());
            $this->assertSame(7425, $sender->fresh()->wallet_balance);
            $this->assertSame(2675, $recipient->fresh()->wallet_balance);
            $credit = WalletTransaction::query()->where('transfer_reference', $entry->transfer_reference)->where('user_id', $recipient->id)->sole();
            $this->assertSame('transfer_credit', $credit->type);
            $this->assertSame($sender->id, $credit->counterparty_id);
            $this->assertSame($recipient->id, $entry->counterparty_id);
            $this->assertSame(10100, $sender->fresh()->wallet_balance + $recipient->fresh()->wallet_balance);
            $this->actingAs($recipient);
            $this->get(route('transactions'))->assertOk()->assertSee($credit->reference)->assertSee($entry->transfer_reference)->assertDontSee($entry->reference);
        }
    }

    #[TestWith(['users'])]
    #[TestWith(['staff'])]
    public function test_unauthorized_roles_cannot_send_or_adjust_balances(string $role): void
    {
        $sender = User::factory()->create(['role' => $role, 'wallet_balance' => 10000]);
        $recipient = User::factory()->create();
        try {
            app(WalletTransfer::class)->send($sender, $recipient->id, '5', (string) Str::uuid());
            $this->fail('Unauthorized transfer succeeded.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(10000, $sender->fresh()->wallet_balance);
        }
        try {
            app(WalletAdjustment::class)->apply($sender, $recipient->id, 'credit', '5', 'Not allowed', (string) Str::uuid());
            $this->fail('Unauthorized adjustment succeeded.');
        } catch (AuthorizationException $exception) {
            $this->assertSame(0, $recipient->fresh()->wallet_balance);
        }
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_recipient_review_supports_email_and_normalized_phone(): void
    {
        $sender = User::factory()->agent()->create(['wallet_balance' => 10000]);
        $recipient = User::factory()->create(['phone' => '08012345678']);
        $this->actingAs($sender);
        $service = app(WalletTransfer::class);
        $this->assertSame($recipient->id, $service->recipient($sender, '+234 801 234 5678')->id);
        $this->assertSame($recipient->id, $service->recipient($sender, strtoupper($recipient->email))->id);
        Livewire::test(WalletTransferForm::class)->set('recipient', $recipient->email)->set('amount', '1.25')->call('preview')
            ->assertSet('recipientId', $recipient->id)->assertSee($recipient->name)->call('confirm')->assertHasNoErrors()->assertSet('recipientId', null)->assertSee('Transfer completed');
        $this->assertSame(9875, $sender->fresh()->wallet_balance);
    }

    public function test_changed_input_requires_review_and_permission_revocation_blocks_confirmation(): void
    {
        $sender = User::factory()->staff(['wallet.transfer'])->create(['wallet_balance' => 10000]);
        $recipient = User::factory()->create();
        $this->actingAs($sender);
        $form = Livewire::test(WalletTransferForm::class)->set('recipient', $recipient->email)->set('amount', '1')->call('preview');
        $form->set('amount', '2')->call('confirm')->assertHasErrors('recipient');
        $form->call('preview');
        $sender->staff_permissions = [];
        $sender->save();
        $form->call('confirm')->assertForbidden();
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_duplicate_phone_is_rejected_instead_of_selecting_an_arbitrary_user(): void
    {
        $sender = User::factory()->agent()->create();
        User::factory()->create(['phone' => '08012345678']);
        User::factory()->create(['phone' => '+2348012345678']);
        $this->actingAs($sender);
        Livewire::test(WalletTransferForm::class)->set('recipient', '08012345678')->set('amount', '1')->call('preview')->assertHasErrors('recipient');
    }

    #[TestWith(['0'])]
    #[TestWith(['-1'])]
    #[TestWith(['0.001'])]
    #[TestWith(['1e3'])]
    #[TestWith(['1,000'])]
    #[TestWith(['1000000000'])]
    public function test_invalid_transfer_amounts_leave_balances_unchanged(string $amount): void
    {
        $sender = User::factory()->agent()->create(['wallet_balance' => 10000]);
        $recipient = User::factory()->create();
        try {
            app(WalletTransfer::class)->send($sender, $recipient->id, $amount, (string) Str::uuid());
            $this->fail('Invalid amount accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
        $this->assertSame(10000, $sender->fresh()->wallet_balance);
        $this->assertSame(0, $recipient->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_transfers_are_idempotent_and_reused_keys_cannot_change_the_amount(): void
    {
        $sender = User::factory()->agent()->create(['wallet_balance' => 10000]);
        $recipient = User::factory()->create();
        $service = app(WalletTransfer::class);
        $key = (string) Str::uuid();
        $entry = $service->send($sender, $recipient->id, '60', $key);
        $this->assertSame($entry->id, $service->send($sender, $recipient->id, '60.00', $key)->id);
        try {
            $service->send($sender, $recipient->id, '61', $key);
            $this->fail('Changed idempotent request accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
        try {
            $service->send($sender, $recipient->id, '60', (string) Str::uuid());
            $this->fail('Overspending accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount', $exception->errors());
        }
        $this->assertSame(4000, $sender->fresh()->wallet_balance);
        $this->assertSame(6000, $recipient->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseCount('admin_audits', 1);
    }

    #[TestWith(['self'])]
    #[TestWith(['suspended'])]
    #[TestWith(['overflow'])]
    public function test_unavailable_recipients_are_rejected(string $case): void
    {
        $sender = User::factory()->agent()->create(['wallet_balance' => 10000]);
        $recipient = $case === 'self' ? $sender : User::factory()->create(['is_active' => $case !== 'suspended', 'wallet_balance' => $case === 'overflow' ? Money::MAX_MINOR : 0]);
        try {
            app(WalletTransfer::class)->send($sender, $recipient->id, '1', (string) Str::uuid());
            $this->fail('Invalid recipient accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame(10000, $sender->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_both_ledger_entries_and_balances_roll_back_when_audit_writing_fails(): void
    {
        $sender = User::factory()->agent()->create(['wallet_balance' => 10000]);
        $recipient = User::factory()->create();
        AdminAudit::creating(function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            app(WalletTransfer::class)->send($sender, $recipient->id, '10', (string) Str::uuid());
            $this->fail('Incomplete transfer committed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            AdminAudit::flushEventListeners();
        }
        $this->assertSame(10000, $sender->fresh()->wallet_balance);
        $this->assertSame(0, $recipient->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }
}
