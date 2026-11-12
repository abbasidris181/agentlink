<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table): void {
            $table->id();
            $table->char('reference', 26)->unique();
            $table->uuid('request_key')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('service', 60);
            $table->string('method', 30);
            $table->text('payload');
            $table->string('screenshot_path')->nullable();
            $table->unsignedBigInteger('quoted_price_minor')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('reply')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'service', 'id']);
            $table->index(['status', 'id']);
            $table->index(['service', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
