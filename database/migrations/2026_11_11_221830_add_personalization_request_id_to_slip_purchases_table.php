<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slip_purchases', function (Blueprint $table): void {
            $table->foreignId('personalization_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['personalization_request_id', 'slip'], 'slip_purchases_personalization_slip_unique');
        });
        Schema::table('personalization_requests', function (Blueprint $table): void {
            $table->string('provider_status', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('slip_purchases', function (Blueprint $table): void {
            $table->dropForeign(['personalization_request_id']);
            $table->dropUnique('slip_purchases_personalization_slip_unique');
            $table->dropColumn('personalization_request_id');
        });
        Schema::table('personalization_requests', function (Blueprint $table): void {
            $table->dropColumn('provider_status');
        });
    }
};
