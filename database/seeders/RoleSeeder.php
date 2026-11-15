<?php

namespace Database\Seeders;

use App\AdminAccess;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [];
        $rules = [];
        foreach (['admin', 'staff'] as $account) {
            $email = config('seeding.'.$account.'.email');
            $accounts[$account] = [
                'name' => config('seeding.'.$account.'.name'),
                'email' => is_string($email) ? Str::lower(trim($email)) : $email,
                'password' => config('seeding.'.$account.'.password'),
            ];
            $rules[$account.'.name'] = ['required', 'string', 'max:255'];
            $rules[$account.'.email'] = ['required', 'string', 'email:rfc', 'max:255'];
            $rules[$account.'.password'] = ['required', 'string'];
        }
        $rules['staff.email'][] = 'different:admin.email';
        $accounts = Validator::make($accounts, $rules, [
            'admin.password.required' => 'Set SEED_ADMIN_PASSWORD before running RoleSeeder.',
            'staff.password.required' => 'Set SEED_STAFF_PASSWORD before running RoleSeeder.',
        ])->validate();

        DB::transaction(function () use ($accounts): void {
            foreach ($accounts as $account => $details) {
                $role = $account === 'admin' ? 'super_admin' : 'staff';
                $existing = User::query()->where('email', $details['email'])->first();
                if ($existing !== null) {
                    if ($existing->role !== $role) {
                        throw ValidationException::withMessages([
                            $account.'.email' => 'This seed email belongs to an account with a different role. Choose another seed email.',
                        ]);
                    }

                    continue;
                }
                $user = new User;
                $user->forceFill([
                    'name' => $details['name'],
                    'first_name' => Str::before($details['name'], ' '),
                    'email' => $details['email'],
                    'password' => $details['password'],
                    'email_verified_at' => now(),
                    'role' => $role,
                    'is_admin' => $role === 'super_admin',
                    'is_active' => true,
                    'wallet_balance' => 0,
                    'staff_permissions' => $role === 'staff'
                        ? AdminAccess::withDependencies(['dashboard.view', 'transactions.view', 'requests.manage'])
                        : [],
                ]);
                $user->save();
            }
        }, 3);
    }
}
