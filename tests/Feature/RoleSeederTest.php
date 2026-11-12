<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'seeding.admin' => ['name' => 'Seed Administrator', 'email' => 'admin@example.test', 'password' => 'Admin-Test-Password-123!'],
            'seeding.staff' => ['name' => 'Seed Staff', 'email' => 'staff@example.test', 'password' => 'Staff-Test-Password-123!'],
        ]);
    }

    public function test_seeder_creates_verified_admin_and_staff_with_hashed_passwords_and_correct_access(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = User::query()->where('email', 'admin@example.test')->sole();
        $staff = User::query()->where('email', 'staff@example.test')->sole();

        $this->assertSame('super_admin', $admin->role);
        $this->assertTrue($admin->is_admin);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->hasVerifiedEmail());
        $this->assertTrue(Hash::check('Admin-Test-Password-123!', $admin->password));
        $this->assertTrue($admin->can('settings.edit'));
        $this->assertTrue($admin->can('roles.manage'));
        $this->assertSame('staff', $staff->role);
        $this->assertFalse($staff->is_admin);
        $this->assertTrue($staff->hasVerifiedEmail());
        $this->assertTrue(Hash::check('Staff-Test-Password-123!', $staff->password));
        $this->assertTrue($staff->can('dashboard.view'));
        $this->assertTrue($staff->can('transactions.view'));
        $this->assertTrue($staff->can('requests.view'));
        $this->assertTrue($staff->can('requests.manage'));
        $this->assertFalse($staff->can('requests.refund'));
        $this->assertFalse($staff->can('settings.edit'));
        $this->assertFalse($staff->can('roles.manage'));
        $this->assertSame(0, $admin->wallet_balance);
        $this->assertSame(0, $staff->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->actingAs($admin)->get(route('admin.settings'))->assertOk();
        $this->flushSession();
        $this->actingAs($staff)->get(route('admin.requests'))->assertOk();
        $this->get(route('admin.settings'))->assertForbidden();
    }

    public function test_database_seeder_calls_role_seeder_even_with_model_events_muted(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.test', 'role' => 'super_admin', 'is_admin' => true]);
        $this->assertDatabaseHas('users', ['email' => 'staff@example.test', 'role' => 'staff', 'is_admin' => false]);
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_reseeding_does_not_duplicate_accounts_reset_passwords_or_change_staff_permissions_and_status(): void
    {
        $this->seed(RoleSeeder::class);
        $staff = User::query()->where('role', 'staff')->sole();
        $admin = User::query()->where('role', 'super_admin')->sole();
        $staff->forceFill(['staff_permissions' => ['prices.view'], 'is_active' => false, 'wallet_balance' => 12345, 'password' => 'Changed-Staff-Password!'])->save();
        $admin->forceFill(['password' => 'Changed-Admin-Password!'])->save();
        $adminHash = $admin->password;
        $staffHash = $staff->password;
        $this->seed(RoleSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertSame($adminHash, $admin->fresh()->password);
        $this->assertSame($staffHash, $staff->fresh()->password);
        $this->assertSame(['prices.view'], $staff->fresh()->staff_permissions);
        $this->assertFalse($staff->fresh()->is_active);
        $this->assertSame(12345, $staff->fresh()->wallet_balance);
    }

    #[TestWith(['admin', null])]
    #[TestWith(['staff', null])]
    #[TestWith(['admin', 'short'])]
    #[TestWith(['staff', 'short'])]
    public function test_missing_or_short_passwords_create_no_accounts(string $account, ?string $password): void
    {
        config(['seeding.'.$account.'.password' => $password]);
        try {
            $this->seed(RoleSeeder::class);
            $this->fail('Seed passwords must be explicitly configured and sufficiently long.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($account.'.password', $exception->errors());
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_duplicate_seed_emails_are_rejected_ignoring_case(): void
    {
        config(['seeding.staff.email' => 'ADMIN@example.test']);
        try {
            $this->seed(RoleSeeder::class);
            $this->fail('Admin and staff must use different email addresses.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('staff.email', $exception->errors());
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_customer_accounts_are_not_promoted_and_new_seed_accounts_roll_back(): void
    {
        $customer = User::factory()->create(['email' => 'staff@example.test', 'wallet_balance' => 4567]);
        $password = $customer->password;
        try {
            $this->seed(RoleSeeder::class);
            $this->fail('A seed email must not overwrite an account with a different role.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('staff.email', $exception->errors());
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
        $this->assertSame('users', $customer->fresh()->role);
        $this->assertSame($password, $customer->fresh()->password);
        $this->assertSame(4567, $customer->fresh()->wallet_balance);
    }

    public function test_seed_emails_are_normalized_before_accounts_are_created(): void
    {
        config(['seeding.admin.email' => ' ADMIN@Example.Test ']);
        $this->seed(RoleSeeder::class);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.test', 'role' => 'super_admin']);
    }
}
