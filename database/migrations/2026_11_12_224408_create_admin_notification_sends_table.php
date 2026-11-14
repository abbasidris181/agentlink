<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notification_sends', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->string('status', 16)->default('draft');
            $table->longText('payload');
            $table->longText('recipients');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'channel', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notification_sends');
    }
};
