<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nin_verification_records', function (Blueprint $table): void {
            $table->string('version', 2)->default('v1');
            $table->index(['user_id', 'version', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('nin_verification_records', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'version', 'id']);
            $table->dropColumn('version');
        });
    }
};
