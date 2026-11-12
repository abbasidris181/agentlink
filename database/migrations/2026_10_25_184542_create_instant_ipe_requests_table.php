<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('instant_ipe_requests')) {
            Schema::create('instant_ipe_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('instant_ipe_submission_id')->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->char('reference', 26)->unique();
                $table->char('tracking_id', 15);
                $table->char('active_tracking_id', 15)->nullable();
                $table->decimal('price', 22, 2)->unsigned();
                $table->string('status', 20)->default('queued');
                $table->string('provider_status', 80)->nullable();
                $table->text('reply')->nullable();
                $table->text('nin')->nullable();
                $table->string('last_error')->nullable();
                $table->timestamp('dispatch_started_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->timestamp('next_check_at')->nullable();
                $table->timestamp('check_lease_until')->nullable();
                $table->uuid('check_token')->nullable();
                $table->timestamp('refunded_at')->nullable();
                $table->timestamps();

            });
        }
        foreach ([
            ['instant_ipe_requests_user_id_active_tracking_id_unique', ['user_id', 'active_tracking_id'], true],
            ['instant_ipe_submission_tracking_unique', ['instant_ipe_submission_id', 'tracking_id'], true],
            ['instant_ipe_requests_user_id_id_index', ['user_id', 'id'], false],
            ['instant_ipe_requests_status_next_check_at_index', ['status', 'next_check_at'], false],
        ] as [$name, $columns, $unique]) {
            if (! Schema::hasIndex('instant_ipe_requests', $name)) {
                Schema::table('instant_ipe_requests', function (Blueprint $table) use ($name, $columns, $unique): void {
                    if ($unique) {
                        $table->unique($columns, $name);
                    } else {
                        $table->index($columns, $name);
                    }
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('instant_ipe_requests');
    }
};
