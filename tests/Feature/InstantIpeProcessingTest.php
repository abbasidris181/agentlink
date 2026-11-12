<?php

namespace Tests\Feature;

use App\InstantIpeService;
use App\Jobs\CheckInstantIpeStatus;
use App\Jobs\SubmitInstantIpeRequest;
use App\Livewire\InstantIpe;
use App\Models\AdminAudit;
use App\Models\InstantIpeRequest;
use App\Models\InstantIpeSubmission;
use App\Models\ServicePrice;
use App\Models\SiteSetting;
use App\Models\User;
use App\Money;
use App\ServiceAvailability;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class InstantIpeProcessingTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.tracking_id.tracking_url' => 'https://ipe.example.test/', 'services.tracking_id.tracking_token' => 'test-token']);
    }

    public function test_bulk_submission_saves_each_charge_and_queues_each_id_atomically(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $submission = $this->submission(['ABC123DEF456GH7', 'XYZ987UVW654ST3']);
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{7}$/', $submission->batch_id);
        $this->assertSame(25000, $submission->user->wallet_balance);
        $this->assertCount(2, $submission->requests);
        Queue::assertPushed(SubmitInstantIpeRequest::class, 2);
        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $submission->user_id, 'amount' => '125.00', 'old_balance' => '500.00', 'new_balance' => '375.00']);
        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $submission->user_id, 'amount' => '125.00', 'old_balance' => '375.00', 'new_balance' => '250.00']);
        $this->actingAs($submission->user)->get(route('transactions'))->assertOk()->assertSee($submission->requests->first()->reference);
        Http::assertNothingSent();
    }

    public function test_repeated_submission_keeps_one_charge_and_one_job(): void
    {
        Queue::fake();
        $submission = $this->submission();
        ServicePrice::query()->update(['amount' => '300.00']);
        $repeat = app(InstantIpeService::class)->submit($submission->user, 'single', ['ABC123DEF456GH7'], $submission->request_key);
        $this->assertSame($submission->id, $repeat->id);
        $this->assertSame(37500, $submission->user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Queue::assertPushed(SubmitInstantIpeRequest::class, 1);
    }

    #[TestWith(['insufficient'])]
    #[TestWith(['missing_price'])]
    #[TestWith(['missing_config'])]
    #[TestWith(['duplicates'])]
    public function test_invalid_submissions_leave_wallet_and_queue_unchanged(string $case): void
    {
        Queue::fake();
        $owner = User::factory()->create(['wallet_balance' => 10000]);
        if ($case !== 'missing_price') {
            ServicePrice::factory()->create(['service' => 'instant-ipe', 'method' => 'bulk', 'amount' => 12500]);
        }
        if ($case === 'missing_config') {
            config(['services.tracking_id.tracking_token' => null]);
        }
        $ids = $case === 'duplicates' ? ['ABC123DEF456GH7', 'ABC123DEF456GH7'] : ['ABC123DEF456GH7'];
        try {
            app(InstantIpeService::class)->submit($owner, 'bulk', $ids, (string) Str::uuid());
            $this->fail('Invalid submission accepted.');
        } catch (ValidationException) {
        }
        $this->assertSame(10000, $owner->fresh()->wallet_balance);
        $this->assertDatabaseCount('instant_ipe_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        Queue::assertNothingPushed();
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_provider_submission_uses_the_configured_endpoint_and_schedules_a_25_minute_check(bool $wrapped): void
    {
        $this->freezeTime();
        Queue::fake();
        $submission = $this->submission();
        $body = ['success' => 'Clearance request submitted successfully.'];
        Http::fake(['https://ipe.example.test/api/clearance' => Http::response($wrapped ? ['response' => json_encode($body), 'error' => ''] : $body)]);
        $record = $submission->requests->sole();
        (new SubmitInstantIpeRequest($record->id))->handle(app(InstantIpeService::class));
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertSame(now()->addMinutes(25)->timestamp, $record->fresh()->next_check_at->timestamp);
        Queue::assertPushed(CheckInstantIpeStatus::class, fn (CheckInstantIpeStatus $job): bool => $job->requestId === $record->id && $job->dueAt === now()->addMinutes(25)->timestamp && $job->delay->timestamp === now()->addMinutes(25)->timestamp);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://ipe.example.test/api/clearance' && $request->method() === 'POST' && $request['token'] === 'test-token' && $request['tracking_id'] === 'ABC123DEF456GH7' && $request->hasHeader('Content-Type', 'application/json'));
        $this->assertNull($record->fresh()->reply);
    }

    #[TestWith(['New'])]
    #[TestWith(['Pending'])]
    #[TestWith(['pending'])]
    public function test_automatic_checks_wait_25_minutes_and_keep_checking_pending_results(string $providerStatus): void
    {
        $this->freezeTime();
        Queue::fake();
        $record = $this->acceptedRequest();
        $due = $record->next_check_at->timestamp;
        Http::fake(['https://ipe.example.test/api/clearance/status' => Http::response(['response' => ['status' => $providerStatus, 'reply' => null, 'nin' => null], 'error' => ''])]);
        $this->assertFalse(app(InstantIpeService::class)->checkRequest($record->id, $due));
        Http::assertNothingSent();
        $this->travel(25)->minutes();
        (new CheckInstantIpeStatus($record->id, $due))->handle(app(InstantIpeService::class));
        Http::assertSentCount(1);
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertSame(now()->addMinutes(25)->timestamp, $record->fresh()->next_check_at->timestamp);
        Queue::assertPushed(CheckInstantIpeStatus::class, fn (CheckInstantIpeStatus $job): bool => $job->dueAt === now()->addMinutes(25)->timestamp);
    }

    public function test_success_stops_all_further_checks_and_retains_only_reply_and_nin(): void
    {
        $this->freezeTime();
        Queue::fake();
        $record = $this->acceptedRequest();
        $due = $record->next_check_at->timestamp;
        Http::fake(['https://ipe.example.test/api/clearance/status' => Http::response(['response' => json_encode(['status' => 'Successful', 'reply' => '2AWZLJC8V1000A9', 'nin' => '46585477880', 'name' => 'SECRET NAME', 'dob' => '01-01-2001']), 'error' => ''])]);
        $this->travel(25)->minutes();
        (new CheckInstantIpeStatus($record->id, $due))->handle(app(InstantIpeService::class));
        $this->assertSame('successful', $record->fresh()->status);
        $this->assertNull($record->fresh()->next_check_at);
        $this->assertSame('46585477880', $record->fresh()->nin);
        $stored = DB::table('instant_ipe_requests')->where('id', $record->id)->first();
        $this->assertSame('46585477880', $stored->nin);
        $this->assertSame('2AWZLJC8V1000A9', $stored->reply);
        $this->assertArrayNotHasKey('nin', $record->fresh()->toArray());
        $this->actingAs($record->user);
        Livewire::test(InstantIpe::class)->assertSee('2AWZLJC8V1000A9')->assertSee('46585477880')->assertDontSee('SECRET NAME')->assertDontSee('01-01-2001');
        app(InstantIpeService::class)->checkRequest($record->id);
        (new CheckInstantIpeStatus($record->id, $due))->handle(app(InstantIpeService::class));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    #[TestWith(['Ready for Abis'])]
    #[TestWith(['A different failure message'])]
    public function test_failed_results_refund_the_original_charge_once_and_keep_the_provider_reply(string $reply): void
    {
        $this->freezeTime();
        Queue::fake();
        $record = $this->acceptedRequest();
        ServicePrice::query()->update(['amount' => '900.00']);
        Http::fake(['https://ipe.example.test/api/clearance/status' => Http::response(['response' => json_encode(['status' => 'Failed', 'reply' => $reply, 'nin' => null, 'name' => null, 'dob' => null]), 'error' => ''])]);
        app(InstantIpeService::class)->checkRequest($record->id);
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertSame($reply, $record->fresh()->reply);
        $this->assertNotNull($record->fresh()->refunded_at);
        $this->assertNull($record->fresh()->next_check_at);
        $this->assertSame(50000, $record->user->fresh()->wallet_balance);
        $refund = $record->walletTransaction->refund()->sole();
        $this->assertSame(12500, $refund->amount);
        app(InstantIpeService::class)->checkRequest($record->id);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(1, AdminAudit::query()->where('event', 'instant_ipe.refunded')->count());
        Http::assertSentCount(1);
        $this->actingAs($record->user);
        Livewire::test(InstantIpe::class)->assertSee($reply)->assertSee('Refunded 125.00');
        Queue::assertPushed(SubmitInstantIpeRequest::class, 1);
    }

    #[TestWith(['timeout'])]
    #[TestWith(['malformed'])]
    #[TestWith(['server_error'])]
    #[TestWith(['outer_error'])]
    public function test_uncertain_submission_is_not_resent_or_refunded_and_is_checked_later(string $case): void
    {
        Queue::fake();
        $record = $this->submission()->requests->sole();
        Http::fake(function () use ($case) {
            return match ($case) {
                'timeout' => throw new ConnectionException('Timeout'),
                'malformed' => Http::response('not-json', 200),
                'server_error' => Http::response([], 500),
                default => Http::response(['error' => 'Provider unavailable']),
            };
        });
        app(InstantIpeService::class)->submitRequest($record->id);
        app(InstantIpeService::class)->submitRequest($record->id);
        $this->assertSame('unconfirmed', $record->fresh()->status);
        $this->assertNotNull($record->fresh()->next_check_at);
        $this->assertSame(37500, $record->user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertSentCount($case === 'timeout' ? 0 : 1);
        Queue::assertPushed(CheckInstantIpeStatus::class);
    }

    public function test_unavailable_service_and_type_keep_existing_instant_ipe_status_checks_available(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $record = $this->acceptedRequest();
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('instant-ipe'), 'value' => '1']);
        SiteSetting::create(['key' => ServiceAvailability::unavailableSettingKey('instant-ipe', 'single'), 'value' => '1']);
        Http::fake(['https://ipe.example.test/api/clearance/status' => Http::response(['status' => 'Pending', 'reply' => null, 'nin' => null])]);
        $this->actingAs($record->user);

        Livewire::test(InstantIpe::class)->assertViewHas('canSubmit', false)->assertSee($record->reference)
            ->assertDontSee('fieldset disabled', false)->call('checkStatus', $record->id)->assertHasNoErrors();

        Http::assertSentCount(1);
        $this->assertSame(37500, $record->user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Queue::assertPushed(CheckInstantIpeStatus::class);
    }

    public function test_manual_check_uses_only_the_signed_in_users_saved_request(): void
    {
        Queue::fake();
        $record = $this->acceptedRequest();
        Http::fake(['https://ipe.example.test/api/clearance/status' => Http::response(['status' => 'Pending', 'reply' => null, 'nin' => null])]);
        $this->actingAs(User::factory()->create());
        Livewire::test(InstantIpe::class)->assertDontSee($record->reference)->call('checkStatus', $record->id)->assertNotFound();
        Http::assertNothingSent();
        $this->actingAs($record->user);
        Livewire::test(InstantIpe::class)->assertSee($record->reference)->call('checkStatus', $record->id)->assertHasNoErrors()
            ->call('checkStatus', $record->id)->assertHasErrors('statusCheck');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['tracking_id'] === $record->tracking_id && $request['token'] === 'test-token');
        Queue::assertPushed(CheckInstantIpeStatus::class);
    }

    public function test_overlapping_manual_and_automatic_checks_share_one_lease(): void
    {
        $this->freezeTime();
        Queue::fake();
        $record = $this->acceptedRequest();
        $this->travel(25)->minutes();
        Http::fake(function () use ($record) {
            $this->assertFalse(app(InstantIpeService::class)->checkRequest($record->id));

            return Http::response(['status' => 'Successful', 'reply' => 'Done', 'nin' => '46585477880']);
        });
        app(InstantIpeService::class)->checkRequest($record->id, $record->next_check_at->timestamp);
        $this->assertSame('successful', $record->fresh()->status);
        Http::assertSentCount(1);
        Queue::assertPushed(CheckInstantIpeStatus::class);
    }

    public function test_wallet_requests_and_database_jobs_roll_back_together_if_submission_audit_fails(): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['wallet_balance' => 50000]);
        ServicePrice::factory()->create(['service' => 'instant-ipe', 'method' => 'single', 'amount' => 12500]);
        AdminAudit::creating(function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            app(InstantIpeService::class)->submit($owner, 'single', ['ABC123DEF456GH7'], (string) Str::uuid());
            $this->fail('Request committed without audit.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            AdminAudit::flushEventListeners();
        }
        $this->assertSame(50000, $owner->fresh()->wallet_balance);
        $this->assertDatabaseCount('instant_ipe_submissions', 0);
        $this->assertDatabaseCount('instant_ipe_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_existing_requests_continue_automatic_checks_when_new_submissions_are_disabled(): void
    {
        Queue::fake();
        $record = $this->acceptedRequest();
        SiteSetting::create(['key' => ServiceAvailability::settingKey('instant-ipe'), 'value' => '1']);
        Http::fake(['https://ipe.example.test/api/clearance/status' => Http::response(['status' => 'Successful', 'reply' => 'Done', 'nin' => null])]);
        app(InstantIpeService::class)->checkRequest($record->id);
        $this->assertSame('successful', $record->fresh()->status);
        Http::assertSentCount(1);
        Queue::assertPushed(CheckInstantIpeStatus::class);
    }

    public function test_a_late_pending_response_cannot_overwrite_a_newer_successful_check(): void
    {
        $this->freezeTime();
        Queue::fake();
        $record = $this->acceptedRequest();
        $calls = 0;
        Http::fake(function () use ($record, &$calls) {
            $calls++;
            if ($calls === 1) {
                $this->travel(61)->seconds();
                app(InstantIpeService::class)->checkRequest($record->id);

                return Http::response(['status' => 'Pending', 'reply' => null, 'nin' => null]);
            }

            return Http::response(['status' => 'Successful', 'reply' => 'Final reply', 'nin' => '46585477880']);
        });
        app(InstantIpeService::class)->checkRequest($record->id);
        $this->assertSame('successful', $record->fresh()->status);
        $this->assertSame('Final reply', $record->fresh()->reply);
        $this->assertNull($record->fresh()->next_check_at);
        Http::assertSentCount(2);
        Queue::assertPushed(CheckInstantIpeStatus::class);
    }

    public function test_manual_pending_checks_preserve_the_automatic_schedule_and_old_jobs_cannot_repeat_it(): void
    {
        $this->freezeTime();
        Queue::fake();
        $record = $this->acceptedRequest();
        $oldDue = $record->next_check_at->timestamp;
        $this->travel(5)->minutes();
        Http::fake(['https://ipe.example.test/api/clearance/status' => Http::response(['status' => 'New', 'reply' => null, 'nin' => null])]);
        app(InstantIpeService::class)->checkRequest($record->id);
        $this->assertSame($oldDue, $record->fresh()->next_check_at->timestamp);
        $this->travel(20)->minutes();
        (new CheckInstantIpeStatus($record->id, $oldDue))->handle(app(InstantIpeService::class));
        Http::assertSentCount(2);
        $this->assertSame($oldDue + 1500, $record->fresh()->next_check_at->timestamp);
        (new CheckInstantIpeStatus($record->id, $oldDue))->handle(app(InstantIpeService::class));
        Http::assertSentCount(2);
        Queue::assertPushed(CheckInstantIpeStatus::class, fn (CheckInstantIpeStatus $job): bool => $job->dueAt === $oldDue + 1500);
    }

    public function test_refund_audit_failure_rolls_back_the_credit_and_retry_refunds_only_once(): void
    {
        $this->freezeTime();
        Queue::fake();
        $record = $this->acceptedRequest();
        Http::fake(['https://ipe.example.test/api/clearance/status' => Http::response(['status' => 'Failed', 'reply' => 'Ready for Abis', 'nin' => null])]);
        AdminAudit::creating(function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            app(InstantIpeService::class)->checkRequest($record->id);
            $this->fail('Refund committed without audit.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            AdminAudit::flushEventListeners();
        }
        $this->assertSame(37500, $record->user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame('pending', $record->fresh()->status);
        $this->travel(61)->seconds();
        app(InstantIpeService::class)->checkRequest($record->id);
        $this->assertSame(50000, $record->user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 2);
        Http::assertSentCount(2);
        Queue::assertPushed(CheckInstantIpeStatus::class);
    }

    public function test_refunds_wait_for_wallet_capacity_without_rechecking_a_confirmed_failure(): void
    {
        Queue::fake();
        $record = $this->acceptedRequest();
        $owner = $record->user;
        $owner->wallet_balance = Money::MAX_MINOR;
        $owner->save();
        Http::fake(['https://ipe.example.test/api/clearance/status' => Http::response(['status' => 'Failed', 'reply' => 'Provider failure', 'nin' => null])]);
        app(InstantIpeService::class)->checkRequest($record->id);
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertNull($record->fresh()->refunded_at);
        $this->assertNotNull($record->fresh()->next_check_at);
        $this->assertSame(Money::MAX_MINOR, $owner->fresh()->wallet_balance);
        $owner->wallet_balance = Money::MAX_MINOR - 12500;
        $owner->save();
        app(InstantIpeService::class)->checkRequest($record->id);
        $this->assertNotNull($record->fresh()->refunded_at);
        $this->assertNull($record->fresh()->next_check_at);
        $this->assertSame(Money::MAX_MINOR, $owner->fresh()->wallet_balance);
        Http::assertSentCount(1);
        Queue::assertPushed(CheckInstantIpeStatus::class);
    }

    public function test_a_confirmed_failure_during_initial_submission_also_refunds_once(): void
    {
        Queue::fake();
        $record = $this->submission()->requests->sole();
        Http::fake(['https://ipe.example.test/api/clearance' => Http::response(['status' => 'Failed', 'reply' => 'Cannot clear this request', 'nin' => null])]);
        app(InstantIpeService::class)->submitRequest($record->id);
        app(InstantIpeService::class)->submitRequest($record->id);
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertSame('Cannot clear this request', $record->fresh()->reply);
        $this->assertSame(50000, $record->user->fresh()->wallet_balance);
        $this->assertNull($record->fresh()->next_check_at);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        Queue::assertPushed(CheckInstantIpeStatus::class);
    }

    public function test_a_free_failed_request_never_creates_a_refund_credit(): void
    {
        Queue::fake();
        $record = $this->submission(price: 0)->requests->sole();
        Http::fake(['https://ipe.example.test/api/clearance' => Http::response(['status' => 'Failed', 'reply' => 'Ready for Abis', 'nin' => null])]);
        app(InstantIpeService::class)->submitRequest($record->id);
        $this->assertSame(50000, $record->user->fresh()->wallet_balance);
        $this->assertNotNull($record->fresh()->refunded_at);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertSentCount(1);
        Queue::assertPushed(CheckInstantIpeStatus::class);
    }

    public function test_database_queue_and_delays_are_real_even_if_default_queue_is_sync(): void
    {
        $this->freezeTime();
        config(['queue.default' => 'sync']);
        Http::fake(['https://ipe.example.test/api/clearance' => Http::response(['success' => 'Accepted'])]);
        $record = $this->submission()->requests->sole();
        $this->assertDatabaseHas('jobs', ['queue' => 'instant-ipe']);
        Http::assertNothingSent();
        app(InstantIpeService::class)->submitRequest($record->id);
        $futureJob = DB::table('jobs')->where('available_at', now()->addMinutes(25)->timestamp)->first();
        $this->assertNotNull($futureJob);
        $this->assertSame('instant-ipe-status', $futureJob->queue);
        $this->assertStringContainsString('CheckInstantIpeStatus', $futureJob->payload);
        Http::assertSentCount(1);
    }

    #[TestWith([200, '{"status":"Pending","reply":null,"token":"test-token"}'])]
    #[TestWith([502, 'upstream unavailable test-token'])]
    #[TestWith([200, 'invalid json test-token'])]
    public function test_all_provider_responses_are_logged_with_http_status_and_without_the_token(int $httpStatus, string $body): void
    {
        Queue::fake();
        $record = $this->acceptedRequest();
        $path = storage_path('framework/testing/ipe-'.Str::uuid().'.log');
        config(['logging.channels.instant_ipe' => ['driver' => 'single', 'path' => $path, 'permission' => 0600]]);
        Log::forgetChannel('instant_ipe');
        Http::preventStrayRequests();
        Http::fake(['https://ipe.example.test/api/clearance/status' => Http::response($body, $httpStatus)]);
        try {
            app(InstantIpeService::class)->checkRequest($record->id);
            $contents = file_get_contents($path);
            $this->assertStringContainsString('Provider response received.', $contents);
            $this->assertStringContainsString('"request_id":'.$record->id, $contents);
            $this->assertStringContainsString('"http_status":'.$httpStatus, $contents);
            $this->assertStringContainsString('[REDACTED]', $contents);
            $this->assertStringNotContainsString('test-token', $contents);
            $this->assertSame($httpStatus === 200 && str_starts_with($body, '{') ? 'pending' : 'unconfirmed', $record->fresh()->status);
        } finally {
            Log::forgetChannel('instant_ipe');
            if (is_file($path)) {
                unlink($path);
            }
        }
        Http::assertSentCount(1);
        Queue::assertPushed(CheckInstantIpeStatus::class);
    }

    #[TestWith(['successful'])]
    #[TestWith(['pending'])]
    #[TestWith(['queued'])]
    #[TestWith(['submitting'])]
    #[TestWith(['failed'])]
    public function test_only_unconfirmed_history_blocks_resubmitting_the_same_tracking_id(string $status): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $owner = User::factory()->create(['wallet_balance' => 50000]);
        ServicePrice::factory()->create(['service' => 'instant-ipe', 'method' => 'single', 'amount' => 12500]);
        $previous = InstantIpeRequest::factory()->create(['user_id' => $owner->id, 'tracking_id' => 'ABC123DEF456GH7', 'active_tracking_id' => 'ABC123DEF456GH7', 'status' => $status]);

        $submission = app(InstantIpeService::class)->submit($owner, 'single', ['ABC123DEF456GH7'], (string) Str::uuid());

        $this->assertNotSame($previous->id, $submission->requests->sole()->id);
        $this->assertSame(37500, $owner->fresh()->wallet_balance);
        $this->assertDatabaseCount('instant_ipe_requests', 2);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame($status, $previous->fresh()->status);
        Queue::assertPushed(SubmitInstantIpeRequest::class, 1);
        Http::assertNothingSent();
    }

    public function test_a_bulk_list_with_an_unconfirmed_tracking_id_is_rejected_without_charging(): void
    {
        Queue::fake();
        $owner = User::factory()->create(['wallet_balance' => 50000]);
        ServicePrice::factory()->create(['service' => 'instant-ipe', 'method' => 'bulk', 'amount' => 12500]);
        InstantIpeRequest::factory()->create(['user_id' => $owner->id, 'tracking_id' => 'ABC123DEF456GH7', 'active_tracking_id' => null, 'status' => 'unconfirmed']);
        $this->actingAs($owner);

        Livewire::test(InstantIpe::class)->set('mode', 'bulk')->set('trackingInput', "XYZ987UVW654ST3\nABC123DEF456GH7")->call('submit')
            ->assertHasErrors('trackingInput')->assertSee('One of these tracking IDs already has a request that needs confirmation in your history.');

        $this->assertSame(50000, $owner->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('instant_ipe_requests', 1);
        Queue::assertNothingPushed();
    }

    public function test_another_users_unconfirmed_request_does_not_block_submission(): void
    {
        Queue::fake();
        InstantIpeRequest::factory()->create(['tracking_id' => 'ABC123DEF456GH7', 'status' => 'unconfirmed']);

        $submission = $this->submission();

        $this->assertCount(1, $submission->requests);
        $this->assertSame(37500, $submission->user->fresh()->wallet_balance);
        Queue::assertPushed(SubmitInstantIpeRequest::class, 1);
    }

    private function submission(array $ids = ['ABC123DEF456GH7'], int $price = 12500): InstantIpeSubmission
    {
        $mode = count($ids) > 1 ? 'bulk' : 'single';
        ServicePrice::factory()->create(['service' => 'instant-ipe', 'method' => $mode, 'amount' => $price]);

        return app(InstantIpeService::class)->submit(User::factory()->create(['wallet_balance' => 50000]), $mode, $ids, (string) Str::uuid());
    }

    private function acceptedRequest(): InstantIpeRequest
    {
        $record = $this->submission()->requests->sole();
        $record->status = 'pending';
        $record->dispatch_started_at = now()->subMinutes(2);
        $record->submitted_at = now();
        $record->next_check_at = now()->addMinutes(25)->startOfSecond();
        $record->save();
        CheckInstantIpeStatus::dispatch($record->id, $record->next_check_at->timestamp)->delay($record->next_check_at);

        return $record;
    }
}
