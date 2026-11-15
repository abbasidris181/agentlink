<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<string, string>> */
    private const FIELDS = [
        'nin_verification_records' => ['nin' => 'text', 'phone' => 'text', 'result' => 'json'],
        'bvn_verification_records' => ['bvn' => 'text', 'result' => 'json'],
        'personalization_requests' => ['tracking_id' => 'text', 'result' => 'json'],
        'service_requests' => ['payload' => 'json', 'reply' => 'text'],
        'admin_notification_sends' => ['payload' => 'json', 'recipients' => 'json'],
        'site_settings' => ['value' => 'json'],
    ];

    public function up(): void
    {
        $this->convertValues(false);
        if (Schema::hasColumn('personalization_requests', 'tracking_hash')) {
            Schema::table('personalization_requests', function (Blueprint $table): void {
                $column = $table->string('tracking_id', 15);
                if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                    $column->collation('utf8mb4_bin');
                }
                $column->change();
            });
            if (! Schema::hasIndex('personalization_requests', 'personalization_requests_user_id_tracking_id_unique')) {
                Schema::table('personalization_requests', function (Blueprint $table): void {
                    $table->unique(['user_id', 'tracking_id']);
                });
            }
            Schema::table('personalization_requests', function (Blueprint $table): void {
                $table->dropUnique(['user_id', 'tracking_hash']);
                $table->dropColumn('tracking_hash');
            });
        }
        if (Schema::hasColumn('instant_ipe_submissions', 'input_hash')) {
            Schema::table('instant_ipe_submissions', function (Blueprint $table): void {
                $table->dropColumn('input_hash');
            });
        }
    }

    public function down(): void
    {
        Schema::table('personalization_requests', function (Blueprint $table): void {
            $table->char('tracking_hash', 64)->nullable();
        });
        Schema::table('instant_ipe_submissions', function (Blueprint $table): void {
            $table->char('input_hash', 64)->nullable();
        });
        DB::transaction(function (): void {
            DB::table('personalization_requests')->orderBy('id')->lockForUpdate()->chunkById(100, function (Collection $rows): void {
                foreach ($rows as $row) {
                    DB::table('personalization_requests')->where('id', $row->id)->update([
                        'tracking_hash' => hash_hmac('sha256', $row->tracking_id, config('app.key')),
                    ]);
                }
            });
            DB::table('instant_ipe_submissions')->orderBy('id')->lockForUpdate()->chunkById(100, function (Collection $rows): void {
                foreach ($rows as $row) {
                    $trackingIds = DB::table('instant_ipe_requests')->where('instant_ipe_submission_id', $row->id)->orderBy('id')->pluck('tracking_id')->all();
                    DB::table('instant_ipe_submissions')->where('id', $row->id)->update([
                        'input_hash' => hash('sha256', json_encode([$row->mode, $trackingIds], JSON_THROW_ON_ERROR)),
                    ]);
                }
            });
        });
        Schema::table('personalization_requests', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'tracking_id']);
            $table->text('tracking_id')->change();
            $table->char('tracking_hash', 64)->nullable(false)->change();
            $table->unique(['user_id', 'tracking_hash']);
        });
        Schema::table('instant_ipe_submissions', function (Blueprint $table): void {
            $table->char('input_hash', 64)->nullable(false)->change();
        });
        $this->convertValues(true);
    }

    private function convertValues(bool $encrypt): void
    {
        DB::transaction(function () use ($encrypt): void {
            foreach (self::FIELDS as $table => $fields) {
                $query = DB::table($table)->select(['id', ...array_keys($fields)]);
                if ($table === 'site_settings') {
                    $query->where('key', 'notification_parrotica');
                }
                $query->lockForUpdate()->chunkById(100, function (Collection $rows) use ($table, $fields, $encrypt): void {
                    foreach ($rows as $row) {
                        $values = [];
                        foreach ($fields as $field => $type) {
                            $original = $row->{$field};
                            if ($original === null) {
                                continue;
                            }
                            $value = $this->plainValue($original);
                            if ($type === 'json') {
                                json_decode($value, flags: JSON_THROW_ON_ERROR);
                            }
                            if ($table === 'personalization_requests' && $field === 'tracking_id'
                                && ! preg_match('/\A[A-Za-z0-9]{15}\z/', $value)) {
                                throw new RuntimeException('A personalization tracking ID is invalid. No data was converted.');
                            }
                            $converted = $encrypt ? Crypt::encryptString($value) : $value;
                            if ($converted !== $original) {
                                $values[$field] = $converted;
                            }
                        }
                        if ($values !== []) {
                            DB::table($table)->where('id', $row->id)->update($values);
                        }
                    }
                });
            }
        });
    }

    private function plainValue(string $value): string
    {
        $payload = json_decode(base64_decode($value, true) ?: '', true);
        if (is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac'])) {
            return Crypt::decryptString($value);
        }

        return $value;
    }
};
