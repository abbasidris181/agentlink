<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('users')->index();
            $table->json('staff_permissions')->nullable();
            $table->string('normalized_phone', 15)->nullable()->index();
        });
        DB::table('users')->where('is_admin', true)->update(['role' => 'super_admin']);
        DB::table('users')->select(['id', 'phone'])->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $phone = trim($user->phone ?? '');
                if (! preg_match('/\A\+?[0-9 ()-]{7,30}\z/', $phone)) {
                    continue;
                }
                $digits = preg_replace('/[^0-9]/', '', $phone);
                if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
                    $digits = '234'.substr($digits, 1);
                } elseif (strlen($digits) === 10 && ! str_starts_with($digits, '0')) {
                    $digits = '234'.$digits;
                }
                if (strlen($digits) >= 7 && strlen($digits) <= 15) {
                    DB::table('users')->where('id', $user->id)->update(['normalized_phone' => $digits]);
                }
            }
        });
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->char('transfer_reference', 26)->nullable()->index();
            $table->foreignId('counterparty_id')->nullable()->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('counterparty_id');
            $table->dropColumn('transfer_reference');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['role', 'staff_permissions', 'normalized_phone']);
        });
    }
};
