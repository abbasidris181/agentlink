<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personalization_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->char('reference', 26)->unique();
            $table->text('tracking_id');
            $table->char('tracking_hash', 64);
            $table->string('provider_id', 80)->nullable();
            $table->string('status', 20)->default('processing');
            $table->longText('result')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'tracking_hash']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personalization_requests');
    }
};
