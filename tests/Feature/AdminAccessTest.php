<?php

namespace Tests\Feature;

use App\Livewire\Admin\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['admin.dashboard'])]
    #[TestWith(['admin.prices'])]
    #[TestWith(['admin.settings'])]
    #[TestWith(['admin.users'])]
    public function test_admin_pages_require_an_active_administrator(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('home'));
        $this->actingAs(User::factory()->create());
        $this->get(route($route))->assertForbidden();
        $this->actingAs(User::factory()->administrator()->create());
        $this->get(route($route))->assertOk()->assertSee('Administration');
    }

    public function test_admin_components_cannot_be_called_by_customers_or_after_access_is_revoked(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(Settings::class)->assertForbidden();
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);
        $component = Livewire::test(Settings::class);
        $admin->role = 'users';
        $admin->save();

        $component->call('save')->assertForbidden();
    }

    public function test_administrator_command_grants_existing_accounts_and_protects_the_last_admin(): void
    {
        $user = User::factory()->create();

        $this->artisan('admin:grant', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);
        $this->artisan('admin:grant', ['email' => $user->email, '--revoke' => true])->assertFailed();
        $this->assertTrue($user->fresh()->is_admin);
        User::factory()->administrator()->create();
        $this->artisan('admin:grant', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_admin);
    }

    public function test_admin_command_does_not_create_unknown_accounts(): void
    {
        $this->artisan('admin:grant', ['email' => 'missing@example.com'])->assertFailed();
        $this->assertDatabaseMissing('users', ['email' => 'missing@example.com']);
    }

    public function test_admin_and_wallet_fields_are_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->fill(['is_admin' => true, 'wallet_balance' => 900000])->save();

        $this->assertFalse($user->fresh()->is_admin);
        $this->assertSame(0, $user->fresh()->wallet_balance);
    }

    public function test_only_admins_see_the_admin_navigation_link(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Admin dashboard');
        $this->actingAs(User::factory()->administrator()->create());
        $this->get(route('dashboard'))->assertOk()->assertSee('Admin dashboard');
    }
}
