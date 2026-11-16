<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monnify_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('mode', 10);
            $table->string('contract_code', 100);
            $table->string('reference', 64)->unique();
            $table->string('customer_email');
            $table->string('customer_name', 200);
            $table->string('reservation_reference', 100)->nullable();
            $table->string('status', 20)->default('creating');
            $table->json('accounts')->nullable();
            $table->json('provider_response')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'mode', 'contract_code']);
            $table->unique(['mode', 'reservation_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monnify_accounts');
    }
};
