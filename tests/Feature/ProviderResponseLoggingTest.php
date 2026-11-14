<?php

namespace Tests\Feature;

use App\InstantIpeService;
use App\Models\AdminNotificationSend;
use App\Models\InstantIpeRequest;
use App\Models\ServicePrice;
use App\Models\User;
use App\NotificationSender;
use App\PersonalizationService;
use App\ProviderResponseLogger;
use App\VerificationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Monolog\Formatter\JsonFormatter;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProviderResponseLoggingTest extends TestCase
{
    use LazilyRefreshDatabase;

    private string $logDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logDirectory = storage_path('framework/testing/provider-logs-'.Str::uuid());
        config([
            'logging.provider_responses.directory' => $this->logDirectory,
            'logging.channels.instant_ipe' => ['driver' => 'daily', 'path' => $this->logDirectory.'/instant-ipe.log', 'permission' => 0600, 'locking' => true, 'formatter' => JsonFormatter::class],
        ]);
        Log::forgetChannel('instant_ipe');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Log::forgetChannel('instant_ipe');
        Log::forgetChannel('ondemand');
        File::deleteDirectory($this->logDirectory);
        parent::tearDown();
    }

    #[TestWith(['nin-v1'])]
    #[TestWith(['nin-v2'])]
    #[TestWith(['nin-v3'])]
    #[TestWith(['bvn-verification'])]
    #[TestWith(['personalization'])]
    #[TestWith(['instant-ipe'])]
    #[TestWith(['whatsapp'])]
    #[TestWith(['parrotica-sms'])]
    public function test_responses_are_saved_in_separate_private_service_named_logs(string $service): void
    {
        $photo = 'data:image/jpeg;base64,'.str_repeat('A', 70000);
        $response = new Response(Http::response(['status' => true, 'message' => ['firstName' => 'TEST', 'photo' => $photo], 'empty' => new \stdClass])->wait());
        ProviderResponseLogger::response($service, $response, ['reference' => 'TEST-REF']);
        $entry = $this->entries($service)[0];
        $this->assertSame($service, $entry['context']['service']);
        $this->assertSame(200, $entry['context']['http_status']);
        $this->assertSame('TEST-REF', $entry['context']['reference']);
        $body = json_decode($entry['context']['response'], flags: JSON_THROW_ON_ERROR);
        $this->assertSame($photo, $body->message->photo);
        $this->assertInstanceOf(\stdClass::class, $body->empty);
        $path = glob($this->logDirectory.'/'.$service.'-*.log')[0];
        $this->assertSame(0600, fileperms($path) & 0777);
    }

    public function test_nested_credentials_and_echoed_secrets_are_redacted_without_removing_provider_results(): void
    {
        $response = new Response(Http::response([
            'message' => ['firstName' => 'TEST', 'idNumber' => '12345678901'],
            'token' => 'unknown-new-token', 'nested' => ['api_key' => 'unknown-key', 'Authorization' => 'Bearer hidden'],
            'echo' => 'configured-secret', 'encoded' => rawurlencode('secret/+value'), 'note' => 'Bearer other-secret',
        ])->wait());
        ProviderResponseLogger::response('nin-v1', $response, [], ['configured-secret', 'secret/+value']);
        $raw = file_get_contents(glob($this->logDirectory.'/nin-v1-*.log')[0]);
        foreach (['unknown-new-token', 'unknown-key', 'configured-secret', 'other-secret', rawurlencode('secret/+value')] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $body = json_decode($this->entries('nin-v1')[0]['context']['response'], true);
        $this->assertSame('12345678901', $body['message']['idNumber']);
        $this->assertSame('[REDACTED]', $body['nested']['api_key']);
    }

    public function test_malformed_error_bodies_and_connection_failures_are_saved_without_secret_exception_messages(): void
    {
        ProviderResponseLogger::response('whatsapp', new Response(Http::response('upstream error: configured-secret', 502)->wait()), ['send_id' => 42], ['configured-secret']);
        ProviderResponseLogger::failure('whatsapp', new ConnectionException('secret-token https://secret.example.test'), ['send_id' => 42]);
        $entries = $this->entries('whatsapp');
        $this->assertSame(502, $entries[0]['context']['http_status']);
        $this->assertSame('upstream error: [REDACTED]', $entries[0]['context']['response']);
        $this->assertSame(ConnectionException::class, $entries[1]['context']['error_type']);
        $this->assertArrayNotHasKey('response', $entries[1]['context']);
        $this->assertStringNotContainsString('secret-token', json_encode($entries));
        $this->assertStringNotContainsString('secret.example.test', json_encode($entries));
    }

    #[TestWith(['nin', 'v1', 'nin-v1'])]
    #[TestWith(['nin', 'v2', 'nin-v2'])]
    #[TestWith(['nin', 'v3', 'nin-v3'])]
    #[TestWith(['phone', 'v1', 'nin-v1'])]
    #[TestWith(['phone', 'v2', 'nin-v2'])]
    #[TestWith(['phone', 'v3', 'nin-v3'])]
    #[TestWith(['bvn', 'v1', 'bvn-verification'])]
    public function test_all_verification_variants_log_provider_errors_with_their_request_reference(string $method, string $version, string $service): void
    {
        config(['services.verify.url' => 'https://verify.example.test', 'services.verify.token' => 'test-token']);
        ServicePrice::factory()->create(['service' => $service, 'method' => $method, 'amount' => 0]);
        $user = User::factory()->create();
        $endpoint = $method === 'nin' && $version === 'v1' ? '/api/v3/verify' : ($version === 'v3' ? '/api/v2/verify' : '/api/v1/verify');
        Http::fake(['https://verify.example.test'.$endpoint => Http::response(['status' => false, 'message' => 'Provider unavailable', 'token' => 'test-token'], 503)]);
        $record = app(VerificationService::class)->verify($user, $method === 'phone' ? '08012345678' : '12345678901', (string) Str::uuid(), $method, $version);
        $entry = $this->entries($service)[0];
        $this->assertSame($record->reference, $entry['context']['reference']);
        $this->assertSame($method, $entry['context']['method']);
        $this->assertSame(503, $entry['context']['http_status']);
        $this->assertStringNotContainsString('test-token', $entry['context']['response']);
        Http::assertSentCount(1);
    }

    public function test_verification_connection_errors_are_logged_and_refund_behavior_is_preserved(): void
    {
        config(['services.verify.url' => 'https://verify.example.test', 'services.verify.token' => 'test-token']);
        ServicePrice::factory()->create(['service' => 'nin-v1', 'method' => 'nin', 'amount' => 100]);
        $user = User::factory()->create(['wallet_balance' => 10000]);
        Http::fake(['https://verify.example.test/api/v3/verify' => fn () => throw new ConnectionException('test-token')]);
        $record = app(VerificationService::class)->verify($user, '12345678901', (string) Str::uuid());
        $this->assertSame('failed', $record->status);
        $this->assertSame(10000, $user->fresh()->wallet_balance);
        $this->assertSame(ConnectionException::class, $this->entries('nin-v1')[0]['context']['error_type']);
    }

    public function test_personalization_submission_and_status_checks_are_both_logged(): void
    {
        config(['services.tracking_id.tracking_url' => 'https://tracking.example.test', 'services.tracking_id.tracking_token' => 'test-token']);
        ServicePrice::factory()->create(['service' => 'personalization', 'method' => 'tracking', 'amount' => 0]);
        $user = User::factory()->create();
        $body = ['success' => true, 'id' => 12345, 'tracking_id' => 'S7Y0OG319000HEM', 'status' => 'In-Progress'];
        Http::fake([
            'https://tracking.example.test/api/personalization' => Http::response($body),
            'https://tracking.example.test/api/personalization/check' => Http::response($body),
        ]);
        $record = app(PersonalizationService::class)->submit($user, 'S7Y0OG319000HEM');
        app(PersonalizationService::class)->check($user, $record->reference);
        $entries = $this->entries('personalization');
        $this->assertSame(['submission', 'check'], array_column(array_column($entries, 'context'), 'operation'));
        Http::assertSentCount(2);
    }

    public function test_instant_ipe_submission_and_checks_keep_the_existing_named_log_channel(): void
    {
        Queue::fake();
        config(['services.tracking_id.tracking_url' => 'https://tracking.example.test', 'services.tracking_id.tracking_token' => 'test-token']);
        $record = InstantIpeRequest::factory()->create();
        Http::fake([
            'https://tracking.example.test/api/clearance' => Http::response(['status' => 'Pending']),
            'https://tracking.example.test/api/clearance/status' => Http::response(['status' => 'Pending']),
        ]);
        app(InstantIpeService::class)->submitRequest($record->id);
        app(InstantIpeService::class)->checkRequest($record->id);
        $this->assertSame(['clearance', 'clearance/status'], array_column(array_column($this->entries('instant-ipe'), 'context'), 'endpoint'));
        Http::assertSentCount(2);
    }

    #[TestWith(['whatsapp', 'whatsapp'])]
    #[TestWith(['phone', 'parrotica-sms'])]
    public function test_notification_provider_responses_are_logged_before_result_classification(string $channel, string $service): void
    {
        $send = $this->notification($channel);
        Http::fake(['https://notification.example.test/*' => Http::response('malformed provider reply', 502)]);
        app(NotificationSender::class)->next($send);
        $entry = $this->entries($service)[0];
        $this->assertSame($send->id, $entry['context']['send_id']);
        $this->assertSame(502, $entry['context']['http_status']);
        $this->assertSame('malformed provider reply', $entry['context']['response']);
        $this->assertSame('stopped', $send->refresh()->status);
        Http::assertSentCount(1);
    }

    #[TestWith(['whatsapp', 'whatsapp'])]
    #[TestWith(['phone', 'parrotica-sms'])]
    public function test_notification_connection_errors_are_logged_without_retrying(string $channel, string $service): void
    {
        $send = $this->notification($channel);
        Http::fake(['https://notification.example.test/*' => fn () => throw new ConnectionException('test-token')]);
        app(NotificationSender::class)->next($send);
        $this->assertSame(ConnectionException::class, $this->entries($service)[0]['context']['error_type']);
        $this->assertSame('stopped', $send->refresh()->status);
        app(NotificationSender::class)->next($send);
        $this->assertCount(1, $this->entries($service));
    }

    private function notification(string $channel): AdminNotificationSend
    {
        return AdminNotificationSend::factory()->create([
            'channel' => $channel, 'status' => 'ready', 'confirmed_at' => now(),
            'payload' => ['subject' => '', 'message' => 'Notice',
                'whatsapp' => ['url' => 'https://notification.example.test/send', 'token' => 'test-token'],
                'parrotica' => ['url' => 'https://notification.example.test', 'api_key' => 'test-token', 'user_id' => 'test-user', 'sender_id' => 'TEST']],
            'recipients' => [['contact' => '+2348123232323', 'users' => [], 'status' => 'pending']],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function entries(string $service): array
    {
        $paths = glob($this->logDirectory.'/'.$service.'-*.log');
        $this->assertCount(1, $paths);

        return array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($paths[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }
}
