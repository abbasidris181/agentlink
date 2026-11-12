<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->char('reference', 26)->unique();
            $table->uuid('request_key')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('service', 100);
            $table->string('type', 20);
            $table->string('status', 20)->default('completed');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('old_balance_minor');
            $table->unsignedBigInteger('new_balance_minor');
            $table->string('reason', 500);
            $table->timestamps();
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
