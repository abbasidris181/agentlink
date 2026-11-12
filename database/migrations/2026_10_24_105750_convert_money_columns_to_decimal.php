<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<string, bool>> */
    private const COLUMNS = [
        'users' => ['wallet_balance' => false],
        'wallet_transactions' => ['amount' => false, 'old_balance' => false, 'new_balance' => false],
        'service_prices' => ['amount' => true],
        'service_requests' => ['quoted_price' => true],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $name => $columns) {
            Schema::table($name, function (Blueprint $table) use ($name, $columns): void {
                foreach ($columns as $column => $nullable) {
                    $definition = $table->decimal($column, 22, 2)->unsigned()->nullable($nullable);
                    if ($name === 'users') {
                        $definition->default(0);
                    }
                    $definition->change();
                }
            });
            $values = [];
            foreach ($columns as $column => $nullable) {
                $values[$column] = DB::raw($column.' / 100');
            }
            DB::table($name)->update($values);
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $name => $columns) {
            $values = [];
            foreach ($columns as $column => $nullable) {
                $values[$column] = DB::raw($column.' * 100');
            }
            DB::table($name)->update($values);
            Schema::table($name, function (Blueprint $table) use ($name, $columns): void {
                foreach ($columns as $column => $nullable) {
                    $definition = $table->unsignedBigInteger($column)->nullable($nullable);
                    if ($name === 'users') {
                        $definition->default(0);
                    }
                    $definition->change();
                }
            });
        }
    }
};
