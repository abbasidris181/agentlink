<?php

namespace Tests\Feature;

use App\Livewire\Admin\Users;
use App\Livewire\Auth\Login;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_user_profiles_and_latest_twenty_transactions_are_scoped_and_ordered(): void
    {
        $admin = User::factory()->administrator()->create();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $transactions = WalletTransaction::factory()->for($user)->for($admin, 'actor')->count(25)->create();
        $otherTransaction = WalletTransaction::factory()->for($other)->for($admin, 'actor')->create();
        $this->actingAs($admin);

        $this->get(route('admin.users.show', ['userId' => $user->id]))->assertOk()
            ->assertSee($user->email)->assertSee('Latest 20 transactions')
            ->assertSeeInOrder([$transactions->last()->reference, $transactions[5]->reference])
            ->assertDontSee($transactions->first()->reference)->assertDontSee($transactions[4]->reference)
            ->assertDontSee($otherTransaction->reference);
    }

    public function test_users_can_be_found_by_email_and_filtered_by_status(): void
    {
        $admin = User::factory()->administrator()->create();
        $active = User::factory()->create(['email' => 'active@example.com']);
        $suspended = User::factory()->suspended()->create(['email' => 'suspended@example.com']);
        $this->actingAs($admin);

        Livewire::test(Users::class)->set('search', 'active@example.com')
            ->assertSee('active@example.com')->assertDontSee('suspended@example.com')
            ->set('search', '')->set('statusFilter', 'suspended')
            ->assertSee('suspended@example.com')->assertDontSee('active@example.com');
    }

    public function test_profile_edits_are_saved_and_email_changes_clear_verification(): void
    {
        $admin = User::factory()->administrator()->create();
        $user = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(Users::class, ['userId' => $user->id])->set('firstName', 'Updated')
            ->set('surname', 'Person')->set('email', 'updated@example.com')->set('business', 'Updated Business')
            ->call('saveProfile')->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Person', 'email' => 'updated@example.com', 'email_verified_at' => null]);
        $this->assertDatabaseHas('admin_audits', ['actor_id' => $admin->id, 'user_id' => $user->id, 'event' => 'user.updated']);
    }

    public function test_duplicate_email_is_rejected_without_altering_the_target(): void
    {
        $admin = User::factory()->administrator()->create();
        $user = User::factory()->create();
        $existing = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(Users::class, ['userId' => $user->id])->set('email', $existing->email)
            ->call('saveProfile')->assertHasErrors(['email']);

        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_password_reset_is_hashed_clears_fields_and_revokes_sessions(): void
    {
        $admin = User::factory()->administrator()->create();
        $user = User::factory()->create();
        $this->actingAs($admin);
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'target-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 'admin-session', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'old-token', 'created_at' => now()]);

        Livewire::test(Users::class, ['userId' => $user->id])->set('newPassword', 'Replacement123!')
            ->set('newPassword_confirmation', 'Replacement123!')->call('resetPassword')->assertHasNoErrors()
            ->assertSet('newPassword', '')->assertSet('newPassword_confirmation', '');

        $this->assertTrue(Hash::check('Replacement123!', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'admin-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('admin_audits', ['user_id' => $user->id, 'event' => 'user.password_reset', 'details' => null]);
    }

    public function test_password_reset_requires_a_confirmed_password_of_sufficient_length(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $user = User::factory()->create();
        $hash = $user->password;

        Livewire::test(Users::class, ['userId' => $user->id])->set('newPassword', 'short')
            ->set('newPassword_confirmation', 'different')->call('resetPassword')->assertHasErrors(['newPassword']);

        $this->assertSame($hash, $user->fresh()->password);
    }

    public function test_suspended_accounts_cannot_sign_in_and_can_be_reactivated(): void
    {
        $admin = User::factory()->administrator()->create();
        $user = User::factory()->create();
        $this->actingAs($admin);
        Livewire::test(Users::class, ['userId' => $user->id])->call('toggleStatus')->assertHasNoErrors();
        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseHas('admin_audits', ['event' => 'user.suspended', 'user_id' => $user->id]);
        Auth::logout();

        Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')
            ->call('login')->assertHasErrors(['email']);
        $this->assertGuest();
        $this->actingAs($admin);
        Livewire::test(Users::class, ['userId' => $user->id])->call('toggleStatus')->assertHasNoErrors();
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_suspended_users_are_signed_out_on_their_next_request(): void
    {
        $user = User::factory()->suspended()->create();
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertRedirect(route('home'));
        $this->assertGuest();
    }

    public function test_admin_accounts_cannot_be_suspended_from_user_management(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);

        Livewire::test(Users::class, ['userId' => $admin->id])->call('toggleStatus')
            ->assertHasErrors(['accountStatus']);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_customer_cannot_access_user_management_even_for_their_own_account(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('admin.users.show', ['userId' => $user->id]))->assertForbidden();
        Livewire::test(Users::class, ['userId' => $user->id])->assertForbidden();
    }

    public function test_missing_users_return_not_found(): void
    {
        $this->actingAs(User::factory()->administrator()->create());

        $this->get(route('admin.users.show', ['userId' => 999999]))->assertNotFound();
    }
}
