<?php

namespace App\Livewire\Admin;

use App\Models\AdminAudit;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Money;
use App\ServiceCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

class Overview extends AdminComponent
{
    public function render(): View
    {
        $show = Gate::allows('dashboard.view');
        $metrics = [];
        if ($show) {
            if (Gate::allows('requests.view')) {
                $metrics['Pending service requests'] = number_format(ServiceRequest::query()->whereIn('service', ServiceRequest::ADMIN_SERVICES)->whereIn('status', ['pending', 'in_progress'])->count());
            }
            $metrics['Total users'] = number_format(User::query()->count());
            $metrics['Active accounts'] = number_format(User::query()->where('is_active', true)->count());
            if (Gate::allows('users.balances')) {
                $metrics['Wallet balances'] = Money::format(Money::fromDecimal((string) User::query()->sum('wallet_balance')));
            }
            if (Gate::allows('users.transactions')) {
                $metrics['Transactions'] = number_format(WalletTransaction::query()->count());
            }
        }
        $columns = ['id', 'name', 'created_at'];
        if (Gate::allows('users.contact')) {
            $columns[] = 'email';
        }

        return view('livewire.admin.overview', [
            'metrics' => $metrics,
            'pricedCount' => $show && Gate::allows('prices.view') ? ServicePrice::query()->whereNotNull('amount')->count() : 0,
            'priceCount' => array_sum(array_map(fn (array $service): int => count($service['methods']), ServiceCatalog::all())),
            'recentUsers' => $show && Gate::allows('users.view') ? User::query()->select($columns)->latest('id')->limit(5)->get() : collect(),
            'recentActivity' => $show && Gate::allows('users.activity') ? AdminAudit::query()->select(['id', 'actor_id', 'user_id', 'event', 'created_at'])->with(['actor:id,name', 'user:id,name'])->latest('id')->limit(10)->get() : collect(),
        ]);
    }
}
