<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slip_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('nin_verification_record_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('bvn_verification_record_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->string('slip', 30);
            $table->decimal('price', 22, 2)->unsigned();
            $table->timestamps();
            $table->unique(['nin_verification_record_id', 'slip'], 'slip_purchases_nin_slip_unique');
            $table->unique(['bvn_verification_record_id', 'slip'], 'slip_purchases_bvn_slip_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slip_purchases');
    }
};
