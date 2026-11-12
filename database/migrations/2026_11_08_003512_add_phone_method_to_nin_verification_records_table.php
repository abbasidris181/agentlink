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
        Schema::table('nin_verification_records', function (Blueprint $table): void {
            $table->string('method', 20)->default('nin');
            $table->text('phone')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nin_verification_records', function (Blueprint $table): void {
            $table->dropColumn(['method', 'phone']);
        });
    }
};
