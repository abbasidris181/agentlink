<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('service', 60);
            $table->string('method', 60);
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->timestamps();
            $table->unique(['service', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_prices');
    }
};
