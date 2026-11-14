<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

abstract class AdminComponent extends Component
{
    public function boot(): void
    {
        Gate::authorize('access-admin');
        $ability = match (static::class) {
            Notifications::class => 'notifications.view', Prices::class => 'prices.view', Settings::class => 'settings.view', Users::class => 'users.view', Requests::class => 'requests.view', Transactions::class => 'transactions.view', default => null,
        };
        if ($ability !== null) {
            Gate::authorize($ability);
        }
    }

    public function logout(): void
    {
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirectRoute('home');
    }
}
