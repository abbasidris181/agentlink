<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monielinks_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monielinks_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('transaction_reference', 160)->unique();
            $table->string('reference', 160);
            $table->unsignedSmallInteger('charge_basis_points');
            $table->string('status', 20)->default('pending');
            $table->decimal('amount_paid', 12, 2);
            $table->decimal('credited_amount', 12, 2)->nullable();
            $table->decimal('fee_amount', 12, 2)->nullable();
            $table->json('event_data');
            $table->string('last_error')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();
            $table->index(['monielinks_account_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monielinks_payments');
    }
};
