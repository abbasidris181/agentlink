<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_prices', function (Blueprint $table): void {
            $table->boolean('charge_errors')->default(false);
        });
        Schema::table('nin_verification_records', function (Blueprint $table): void {
            $table->boolean('charge_errors')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nin_verification_records', function (Blueprint $table): void {
            $table->dropColumn('charge_errors');
        });
        Schema::table('service_prices', function (Blueprint $table): void {
            $table->dropColumn('charge_errors');
        });
    }
};
