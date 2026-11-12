<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nin_verification_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->char('reference', 26)->unique();
            $table->uuid('request_key')->unique();
            $table->text('nin');
            $table->decimal('price', 22, 2)->unsigned();
            $table->string('status', 20)->default('processing');
            $table->longText('result')->nullable();
            $table->string('provider_reference')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nin_verification_records');
    }
};
