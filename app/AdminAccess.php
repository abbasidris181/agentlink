<?php

namespace App;

use App\Models\User;

class AdminAccess
{
    public const ROLES = ['super_admin', 'staff', 'agent', 'users'];

    /** @return array<string, string> */
    public static function permissions(): array
    {
        return ['transactions.view' => 'View all transactions', 'dashboard.view' => 'Dashboard overview', 'prices.view' => 'View prices', 'prices.edit' => 'Edit prices',
            'settings.view' => 'View site settings', 'settings.edit' => 'Edit site settings',
            'notifications.view' => 'View notification recipients', 'notifications.send' => 'Send notifications', 'notifications.configure' => 'Configure notification providers',
            'users.view' => 'View user directory', 'users.contact' => 'View contact and profile fields',
            'users.balances' => 'View balances', 'users.transactions' => 'View latest 20 transactions',
            'users.activity' => 'View account activity', 'users.edit' => 'Edit customer profiles',
            'users.password' => 'Reset customer passwords', 'users.status' => 'Suspend or reactivate customers',
            'requests.view' => 'View service requests and submitted details', 'requests.manage' => 'Update service request status and replies', 'requests.refund' => 'Refund service request payments',
            'wallet.transfer' => 'Transfer from own wallet'];
    }

    /** @param list<string> $permissions
     * @return list<string>
     */
    public static function withDependencies(array $permissions): array
    {
        foreach ($permissions as $permission) {
            $section = explode('.', $permission)[0];
            if (in_array($section, ['prices', 'settings', 'users', 'requests', 'notifications'], true)) {
                $permissions[] = $section.'.view';
            }
            if ($permission === 'users.edit') {
                $permissions[] = 'users.contact';
            }
            if ($permission === 'users.transactions') {
                $permissions[] = 'users.balances';
            }
        }

        return array_values(array_unique($permissions));
    }

    public static function allows(User $user, string $ability): bool
    {
        if (! $user->is_active || $user->needsEmailVerification()) {
            return false;
        }
        if ($ability === 'access-admin') {
            return in_array($user->role, ['super_admin', 'staff'], true);
        }
        if ($user->role === 'super_admin') {
            return true;
        }
        if ($ability === 'wallet.transfer' && $user->role === 'agent') {
            return true;
        }

        return $user->role === 'staff' && array_key_exists($ability, self::permissions()) && in_array($ability, $user->staff_permissions ?? [], true);
    }
}
