<?php

namespace Tests\Feature;

use App\Models\InstantIpeRequest;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstantIpePlainTextStorageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_new_results_are_stored_as_plain_text_and_remain_hidden_from_serialization(): void
    {
        $record = InstantIpeRequest::factory()->create(['reply' => 'Ready for Abis', 'nin' => '01234567890']);

        $this->assertDatabaseHas('instant_ipe_requests', ['id' => $record->id, 'reply' => 'Ready for Abis', 'nin' => '01234567890']);
        $this->assertSame('Ready for Abis', $record->fresh()->reply);
        $this->assertSame('01234567890', $record->fresh()->nin);
        $this->assertArrayNotHasKey('reply', $record->fresh()->toArray());
        $this->assertArrayNotHasKey('nin', $record->fresh()->toArray());
    }

    public function test_migration_decrypts_existing_results_preserves_plain_text_and_nulls_and_can_be_repeated(): void
    {
        $record = InstantIpeRequest::factory()->create();
        DB::table('instant_ipe_requests')->where('id', $record->id)->update(['reply' => Crypt::encryptString('CLEARED'), 'nin' => Crypt::encryptString('01234567890')]);
        $plain = InstantIpeRequest::factory()->create(['reply' => 'Ready for Abis', 'nin' => null]);
        $empty = InstantIpeRequest::factory()->create(['reply' => '', 'nin' => null]);
        $migration = $this->migration();

        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('instant_ipe_requests', ['id' => $record->id, 'reply' => 'CLEARED', 'nin' => '01234567890']);
        $this->assertDatabaseHas('instant_ipe_requests', ['id' => $plain->id, 'reply' => 'Ready for Abis', 'nin' => null]);
        $this->assertDatabaseHas('instant_ipe_requests', ['id' => $empty->id, 'reply' => '', 'nin' => null]);
        $this->assertSame('CLEARED', $record->fresh()->reply);
    }

    public function test_rollback_restores_encryption_and_preserves_nulls(): void
    {
        $record = InstantIpeRequest::factory()->create(['reply' => 'CLEARED', 'nin' => '01234567890']);
        $empty = InstantIpeRequest::factory()->create(['reply' => null, 'nin' => null]);

        $this->migration()->down();

        $stored = DB::table('instant_ipe_requests')->find($record->id);
        $this->assertSame('CLEARED', Crypt::decryptString($stored->reply));
        $this->assertSame('01234567890', Crypt::decryptString($stored->nin));
        $this->assertDatabaseHas('instant_ipe_requests', ['id' => $empty->id, 'reply' => null, 'nin' => null]);
    }

    public function test_invalid_encrypted_data_stops_migration_without_partially_converting_other_rows(): void
    {
        $first = InstantIpeRequest::factory()->create();
        $encrypted = Crypt::encryptString('CLEARED');
        DB::table('instant_ipe_requests')->where('id', $first->id)->update(['reply' => $encrypted]);
        $invalid = InstantIpeRequest::factory()->create();
        $payload = json_decode(base64_decode(Crypt::encryptString('BROKEN')), true);
        $payload['mac'] = str_repeat('0', 64);
        DB::table('instant_ipe_requests')->where('id', $invalid->id)->update(['reply' => base64_encode(json_encode($payload))]);

        try {
            $this->migration()->up();
            $this->fail('Invalid ciphertext was silently accepted.');
        } catch (DecryptException) {
            $this->assertDatabaseHas('instant_ipe_requests', ['id' => $first->id, 'reply' => $encrypted]);
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_26_114128_store_instant_ipe_results_as_plain_text.php');
    }
}
