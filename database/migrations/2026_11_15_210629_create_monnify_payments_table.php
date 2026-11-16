<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monnify_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monnify_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('mode', 10);
            $table->string('transaction_reference', 160);
            $table->string('payment_reference', 160);
            $table->unsignedSmallInteger('charge_basis_points');
            $table->string('status', 20)->default('pending');
            $table->decimal('amount_paid', 12, 2)->nullable();
            $table->decimal('settlement_amount', 12, 2)->nullable();
            $table->decimal('credited_amount', 12, 2)->nullable();
            $table->decimal('fee_amount', 12, 2)->nullable();
            $table->json('event_data');
            $table->json('verification_response')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();
            $table->unique(['mode', 'transaction_reference']);
            $table->index(['monnify_account_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monnify_payments');
    }
};
