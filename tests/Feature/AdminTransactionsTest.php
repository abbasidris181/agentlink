<?php

namespace Tests\Feature;

use App\Livewire\Admin\Transactions;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminTransactionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['all'])]
    #[TestWith(['instant-ipe'])]
    #[TestWith(['nin'])]
    #[TestWith(['bvn'])]
    public function test_transaction_routes_require_admin_access_and_transaction_permission(string $group): void
    {
        $url = route('admin.transactions', ['transactionGroup' => $group]);
        $this->get($url)->assertRedirect(route('home'));
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->agent()->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->staff(['dashboard.view'])->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->administrator()->create())->get($url)->assertOk()->assertSee(Transactions::GROUPS[$group]);
    }

    public function test_admin_sidebar_contains_links_to_all_four_transaction_views(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $response = $this->get(route('admin.dashboard'))->assertOk();
        foreach (Transactions::GROUPS as $group => $label) {
            $response->assertSee($label)->assertSee(route('admin.transactions', ['transactionGroup' => $group]), false);
        }
        $this->get(route('admin.transactions'))->assertOk()->assertSee('All transactions');
    }

    public function test_all_transactions_include_multiple_users_and_all_services_with_plain_number_balances(): void
    {
        $owner = User::factory()->create(['name' => 'Customer One']);
        $other = User::factory()->create(['name' => 'Customer Two']);
        $admin = User::factory()->administrator()->create();
        $first = WalletTransaction::factory()->create(['user_id' => $owner->id, 'actor_id' => $admin->id, 'service' => 'Instant IPE', 'amount' => 12500, 'old_balance' => 50000, 'new_balance' => 37500]);
        $second = WalletTransaction::factory()->create(['user_id' => $other->id, 'actor_id' => $admin->id, 'service' => 'BVN retrieval']);
        $this->actingAs($admin);

        $this->get(route('admin.transactions'))->assertOk()->assertSee($first->reference)->assertSee($second->reference)
            ->assertSee('Customer One')->assertSee('Customer Two')->assertSee($owner->email)
            ->assertSee('125.00')->assertSee('500.00')->assertSee('375.00')->assertDontSee('NGN');
    }

    #[TestWith(['instant-ipe', ['Instant IPE', 'Instant IPE refund']])]
    #[TestWith(['nin', ['NIN V1', 'NIN V2', 'NIN V3']])]
    #[TestWith(['bvn', ['BVN verification', 'BVN retrieval', 'BVN retrieval refund']])]
    public function test_service_views_include_only_matching_transactions_and_refunds(string $group, array $services): void
    {
        $admin = User::factory()->administrator()->create();
        $owner = User::factory()->create();
        $records = [];
        foreach (['Instant IPE', 'Instant IPE refund', 'NIN V1', 'NIN V2', 'NIN V3', 'BVN verification', 'BVN retrieval', 'BVN retrieval refund', 'Wallet credit', 'IPE clearing'] as $service) {
            $records[$service] = WalletTransaction::factory()->create(['user_id' => $owner->id, 'actor_id' => $admin->id, 'service' => $service]);
        }
        $this->actingAs($admin);

        $response = $this->get(route('admin.transactions', ['transactionGroup' => $group]))->assertOk();

        foreach ($records as $service => $transaction) {
            if (in_array($service, $services, true)) {
                $response->assertSee($transaction->reference);
            } else {
                $response->assertDontSee($transaction->reference);
            }
        }
    }

    public function test_staff_permission_controls_navigation_and_livewire_access_and_can_be_revoked(): void
    {
        $owner = User::factory()->create();
        $record = WalletTransaction::factory()->create(['user_id' => $owner->id]);
        $staff = User::factory()->staff(['transactions.view'])->create();
        $this->actingAs($staff);
        $this->get(route('admin.dashboard'))->assertSee('All transactions');
        $form = Livewire::test(Transactions::class)->assertSee($record->reference)->assertDontSee($owner->email);

        $staff->staff_permissions = [];
        $staff->save();
        $form->call('$refresh')->assertForbidden();
        $this->get(route('admin.dashboard'))->assertDontSee('All transactions');
        Livewire::test(Transactions::class)->assertForbidden();
    }

    public function test_pagination_keeps_the_selected_service_group(): void
    {
        $admin = User::factory()->administrator()->create();
        $owner = User::factory()->create();
        $records = WalletTransaction::factory()->count(21)->create(['service' => 'Instant IPE', 'user_id' => $owner->id, 'actor_id' => $admin->id]);
        $other = WalletTransaction::factory()->create(['service' => 'BVN retrieval', 'user_id' => $owner->id, 'actor_id' => $admin->id]);
        $this->actingAs($admin);

        Livewire::test(Transactions::class, ['transactionGroup' => 'instant-ipe'])
            ->assertSee($records->last()->reference)->assertDontSee($records->first()->reference)
            ->call('setPage', 2)->assertSee($records->first()->reference)->assertDontSee($other->reference)
            ->assertSet('transactionGroup', 'instant-ipe');
    }

    public function test_invalid_transaction_groups_are_not_found(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $this->get('/admin/transactions/unknown')->assertNotFound();
        Livewire::test(Transactions::class, ['transactionGroup' => 'unknown'])->assertNotFound();
    }

    public function test_customer_names_are_escaped_in_the_table(): void
    {
        $admin = User::factory()->administrator()->create();
        $owner = User::factory()->create(['name' => '<script>alert(1)</script>']);
        WalletTransaction::factory()->create(['user_id' => $owner->id, 'actor_id' => $admin->id]);
        $this->actingAs($admin)->get(route('admin.transactions'))->assertOk()
            ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
    }
}
