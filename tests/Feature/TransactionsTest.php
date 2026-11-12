<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TransactionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_cannot_access_transaction_history(): void
    {
        $this->get(route('transactions'))->assertRedirect(route('home'));
        Livewire::test(Dashboard::class, ['showTransactions' => true])->assertForbidden();
    }

    public function test_signed_in_users_see_transaction_history_and_balance_columns(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('transactions'))->assertOk()->assertViewIs('transactions')
            ->assertSeeLivewire(Dashboard::class)
            ->assertSeeInOrder(['Transaction history', 'Transaction ID', 'Service', 'Status', 'Old balance', 'New balance', 'Date', 'No transactions yet'])
            ->assertDontSee('Welcome back,')->assertDontSee('Explore services')
            ->assertDontSee('Transaction history will be available when your wallet is ready.');
    }

    public function test_dashboard_links_to_transaction_history(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('href="'.route('transactions').'"', false)
            ->assertSee('Transactions')->assertSee('View Transactions')
            ->assertDontSee('transactionsDialog');
    }

    public function test_transaction_history_cannot_be_combined_with_a_service_page(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Dashboard::class, ['showTransactions' => true, 'isDelinking' => true])
            ->assertNotFound();
    }
}
