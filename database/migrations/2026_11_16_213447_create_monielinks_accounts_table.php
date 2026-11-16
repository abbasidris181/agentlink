<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monielinks_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('client_id');
            $table->string('customer_email')->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('status', 20)->default('creating');
            $table->string('account_number', 10)->nullable()->unique();
            $table->string('account_name', 200)->nullable();
            $table->string('bank', 100)->nullable();
            $table->string('reference', 160)->nullable()->unique();
            $table->json('provider_response')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monielinks_accounts');
    }
};
