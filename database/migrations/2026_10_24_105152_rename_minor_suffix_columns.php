<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('wallet_balance_minor', 'wallet_balance');
        });
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->renameColumn('amount_minor', 'amount');
            $table->renameColumn('old_balance_minor', 'old_balance');
            $table->renameColumn('new_balance_minor', 'new_balance');
        });
        Schema::table('service_prices', function (Blueprint $table): void {
            $table->renameColumn('amount_minor', 'amount');
        });
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->renameColumn('quoted_price_minor', 'quoted_price');
        });
    }

    public function down(): void
    {
        Schema::table('service_requests', function (Blueprint $table): void {
            $table->renameColumn('quoted_price', 'quoted_price_minor');
        });
        Schema::table('service_prices', function (Blueprint $table): void {
            $table->renameColumn('amount', 'amount_minor');
        });
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->renameColumn('amount', 'amount_minor');
            $table->renameColumn('old_balance', 'old_balance_minor');
            $table->renameColumn('new_balance', 'new_balance_minor');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->renameColumn('wallet_balance', 'wallet_balance_minor');
        });
    }
};
