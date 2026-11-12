<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instant_ipe_requests', function (Blueprint $table): void {
            $table->timestamp('admin_replied_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instant_ipe_requests', function (Blueprint $table): void {
            $table->dropColumn('admin_replied_at');
        });
    }
};
