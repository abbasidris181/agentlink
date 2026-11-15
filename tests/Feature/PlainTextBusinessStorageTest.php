<?php

namespace Tests\Feature;

use App\InstantIpeService;
use App\Models\AdminNotificationSend;
use App\Models\BvnVerificationRecord;
use App\Models\InstantIpeRequest;
use App\Models\InstantIpeSubmission;
use App\Models\NinVerificationRecord;
use App\Models\PersonalizationRequest;
use App\Models\ServiceRequest;
use App\Models\SiteSetting;
use App\Models\User;
use App\ParroticaSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlainTextBusinessStorageTest extends TestCase
{
    use DatabaseMigrations;

    public function test_all_business_fields_are_restored_to_plain_text_and_passwords_stay_hashed(): void
    {
        $user = User::factory()->create(['password' => 'a']);
        $passwordHash = $user->password;
        $trackingIds = ['ZZZ000000000001', 'AAA000000000002'];
        $batch = InstantIpeSubmission::factory()->create(['user_id' => $user->id, 'mode' => 'bulk']);
        foreach ($trackingIds as $trackingId) {
            InstantIpeRequest::factory()->create(['user_id' => $user->id, 'instant_ipe_submission_id' => $batch->id, 'tracking_id' => $trackingId]);
        }
        $nin = NinVerificationRecord::factory()->create(['user_id' => $user->id, 'nin' => '01234567890', 'phone' => '08012345678', 'result' => ['firstName' => 'TEST', 'photo' => 'data:image/jpeg;base64,AAAA']]);
        $bvn = BvnVerificationRecord::factory()->create(['user_id' => $user->id, 'bvn' => '22484232222', 'result' => ['firstName' => 'TEST']]);
        $personalization = PersonalizationRequest::factory()->create(['user_id' => $user->id, 'tracking_id' => 'S7Y0OG319000HEM', 'result' => ['idNumber' => '01234567890']]);
        $lowercase = PersonalizationRequest::factory()->create(['user_id' => $user->id, 'tracking_id' => 's7y0og319000hem']);
        $request = ServiceRequest::factory()->create(['user_id' => $user->id, 'payload' => ['nin' => '01234567890'], 'reply' => 'Completed']);
        $send = AdminNotificationSend::factory()->create(['user_id' => $user->id, 'payload' => ['subject' => 'News', 'message' => 'Hello'], 'recipients' => [['contact' => 'test@example.test', 'users' => [(string) $user->id], 'status' => 'pending']]]);
        ParroticaSettings::save(['url' => 'https://sms.example.test', 'user_id' => 'test', 'sender_id' => 'TEST', 'api_key' => 'test-key']);
        $setting = SiteSetting::query()->where('key', 'notification_parrotica')->sole();
        $cases = [
            ['nin_verification_records', $nin->id, ['nin', 'phone', 'result']],
            ['bvn_verification_records', $bvn->id, ['bvn', 'result']],
            ['personalization_requests', $personalization->id, ['tracking_id', 'result']],
            ['service_requests', $request->id, ['payload', 'reply']],
            ['admin_notification_sends', $send->id, ['payload', 'recipients']],
            ['site_settings', $setting->id, ['value']],
        ];
        $before = [];
        foreach ($cases as [$table, $id, $fields]) {
            foreach ($fields as $field) {
                $before[$table][$field] = DB::table($table)->where('id', $id)->value($field);
            }
        }

        $migration = $this->migration();
        $migration->down();
        $this->assertSame(64, strlen(DB::table('instant_ipe_submissions')->where('id', $batch->id)->value('input_hash')));
        foreach ($cases as [$table, $id, $fields]) {
            foreach ($fields as $field) {
                $encrypted = DB::table($table)->where('id', $id)->value($field);
                $this->assertNotSame($before[$table][$field], $encrypted);
                $this->assertSame($before[$table][$field], Crypt::decryptString($encrypted));
            }
        }
        $migration->up();

        foreach ($cases as [$table, $id, $fields]) {
            foreach ($fields as $field) {
                $this->assertSame($before[$table][$field], DB::table($table)->where('id', $id)->value($field));
            }
        }
        $this->assertSame($passwordHash, $user->fresh()->password);
        $this->assertTrue(Hash::check('a', $user->fresh()->password));
        $this->assertSame('01234567890', $nin->fresh()->nin);
        $this->assertSame('data:image/jpeg;base64,AAAA', $nin->fresh()->result['photo']);
        $this->assertSame('22484232222', $bvn->fresh()->bvn);
        $this->assertSame('Completed', $request->fresh()->reply);
        $this->assertSame('Hello', $send->fresh()->payload['message']);
        $this->assertSame('test-key', ParroticaSettings::get()['api_key']);
        $this->assertFalse(Schema::hasColumn('personalization_requests', 'tracking_hash'));
        $this->assertFalse(Schema::hasColumn('instant_ipe_submissions', 'input_hash'));
        $this->assertSame($personalization->id, PersonalizationRequest::query()->where('user_id', $user->id)->where('tracking_id', 'S7Y0OG319000HEM')->sole()->id);
        $this->assertSame($lowercase->id, PersonalizationRequest::query()->where('user_id', $user->id)->where('tracking_id', 's7y0og319000hem')->sole()->id);
        $this->assertArrayNotHasKey('result', $nin->fresh()->toArray());
        $this->assertArrayNotHasKey('payload', $request->fresh()->toArray());
        $this->assertArrayNotHasKey('recipients', $send->fresh()->toArray());
        config(['services.tracking_id.tracking_url' => 'https://tracking.example.test', 'services.tracking_id.tracking_token' => 'test-token']);
        Http::preventStrayRequests();
        $repeat = app(InstantIpeService::class)->submit($user, 'bulk', $trackingIds, $batch->request_key);
        $this->assertSame($batch->id, $repeat->id);
        try {
            app(InstantIpeService::class)->submit($user, 'bulk', array_reverse($trackingIds), $batch->request_key);
            $this->fail('Reusing a reference for different inputs must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('trackingInput', $exception->errors());
        }
        $this->assertDatabaseCount('instant_ipe_submissions', 1);
        $this->assertSame($trackingIds, $batch->requests()->orderBy('id')->pluck('tracking_id')->all());
        Http::assertNothingSent();
    }

    public function test_mixed_plain_and_encrypted_columns_and_null_values_are_preserved(): void
    {
        $record = NinVerificationRecord::factory()->create(['nin' => '01234567890', 'phone' => null, 'result' => ['firstName' => 'TEST']]);
        $migration = $this->migration();
        $migration->down();
        DB::table('nin_verification_records')->where('id', $record->id)->update(['nin' => '01234567890']);
        $migration->up();

        $stored = DB::table('nin_verification_records')->where('id', $record->id)->first();
        $this->assertSame('01234567890', $stored->nin);
        $this->assertNull($stored->phone);
        $this->assertSame(['firstName' => 'TEST'], json_decode($stored->result, true));
        $migration->up();
        $this->assertSame('01234567890', $record->fresh()->nin);
    }

    public function test_corrupt_encryption_rolls_back_conversion_without_removing_fingerprints_or_changing_other_rows(): void
    {
        $first = NinVerificationRecord::factory()->create(['nin' => '01234567890', 'result' => ['firstName' => 'FIRST']]);
        $second = NinVerificationRecord::factory()->create(['nin' => '12345678901', 'result' => ['firstName' => 'SECOND']]);
        $migration = $this->migration();
        $migration->down();
        $originalFirst = DB::table('nin_verification_records')->where('id', $first->id)->first();
        $validResult = DB::table('nin_verification_records')->where('id', $second->id)->value('result');
        $envelope = json_decode(base64_decode($validResult), true);
        $envelope['mac'] = str_repeat('0', 64);
        $corrupt = base64_encode(json_encode($envelope));
        DB::table('nin_verification_records')->where('id', $second->id)->update(['result' => $corrupt]);
        try {
            $migration->up();
            $this->fail('The migration must not overwrite data it cannot decrypt.');
        } catch (DecryptException) {
            $this->assertSame($originalFirst->nin, DB::table('nin_verification_records')->where('id', $first->id)->value('nin'));
            $this->assertSame($originalFirst->result, DB::table('nin_verification_records')->where('id', $first->id)->value('result'));
            $this->assertSame($corrupt, DB::table('nin_verification_records')->where('id', $second->id)->value('result'));
            $this->assertTrue(Schema::hasColumn('personalization_requests', 'tracking_hash'));
            $this->assertTrue(Schema::hasColumn('instant_ipe_submissions', 'input_hash'));
        } finally {
            DB::table('nin_verification_records')->where('id', $second->id)->update(['result' => $validResult]);
            $migration->up();
        }
        $this->assertSame('FIRST', $first->fresh()->result['firstName']);
        $this->assertSame('SECOND', $second->fresh()->result['firstName']);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_15_003000_store_business_data_as_plain_text.php');
    }
}
