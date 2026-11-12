<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->convertResults(false);
    }

    public function down(): void
    {
        $this->convertResults(true);
    }

    private function convertResults(bool $encrypt): void
    {
        DB::transaction(function () use ($encrypt): void {
            DB::table('instant_ipe_requests')->select(['id', 'reply', 'nin'])->lockForUpdate()
                ->chunkById(100, function (Collection $records) use ($encrypt): void {
                    foreach ($records as $record) {
                        $values = [];
                        foreach (['reply', 'nin'] as $field) {
                            $value = $record->{$field};
                            if ($value === null) {
                                continue;
                            }
                            if ($encrypt) {
                                $values[$field] = Crypt::encryptString($value);
                            } else {
                                $payload = json_decode(base64_decode($value, true) ?: '', true);
                                if (is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac'])) {
                                    $values[$field] = Crypt::decryptString($value);
                                }
                            }
                        }
                        if ($values !== []) {
                            DB::table('instant_ipe_requests')->where('id', $record->id)->update($values);
                        }
                    }
                });
        });
    }
};
