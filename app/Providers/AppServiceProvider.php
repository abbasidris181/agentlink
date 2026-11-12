<?php

namespace App\Providers;

use App\AdminAccess;
use App\Http\Middleware\EnsureSignupEmailIsVerified;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Livewire::addPersistentMiddleware([EnsureSignupEmailIsVerified::class]);
        foreach (array_merge(['access-admin', 'roles.manage', 'wallet.adjust'], array_keys(AdminAccess::permissions())) as $ability) {
            Gate::define($ability, function (User $user) use ($ability): bool {
                $current = User::query()->find($user->id);

                return $current !== null && AdminAccess::allows($current, $ability);
            });
        }
    }
}
