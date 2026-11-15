<?php

namespace Tests\Feature;

use App\Livewire\Personalization;
use App\Models\PersonalizationRequest;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PersonalizationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.tracking_id.tracking_url' => 'https://tracking.example.test/', 'services.tracking_id.tracking_token' => 'test-token']);
        Http::preventStrayRequests();
    }

    public function test_personalization_requires_sign_in_and_opens_from_the_dashboard(): void
    {
        $this->get(route('personalization'))->assertRedirect(route('home'));
        Livewire::test(Personalization::class)->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk()->assertSee('href="'.route('personalization').'"', false);
        $this->get(route('personalization'))->assertOk()->assertSeeLivewire(Personalization::class)
            ->assertSee('Tracking ID')->assertSee('Submit')->assertSee('Personalization history');
    }

    public function test_submission_sends_the_contract_and_persists_one_private_request(): void
    {
        ServicePrice::factory()->create(['service' => 'personalization', 'method' => 'tracking', 'amount' => 0]);
        $user = User::factory()->create(['wallet_balance' => 100000]);
        Http::fake(['https://tracking.example.test/api/personalization' => Http::response($this->response())]);
        $this->actingAs($user);

        $component = Livewire::test(Personalization::class)->set('trackingId', ' S7Y0OG319000HEM ')->call('submit')
            ->assertHasNoErrors()->assertSet('notice', 'submission successful')->assertSet('trackingId', 'S7Y0OG319000HEM')->assertSee('In-Progress')->assertSee('Check status')->assertSet('selectedReference', '')->assertDontSee('id="personalization-result-heading"', false);
        $record = PersonalizationRequest::query()->sole();
        $this->assertSame('in_progress', $record->status);
        $this->assertSame('12345', $record->provider_id);
        $this->assertSame('S7Y0OG319000HEM', $record->tracking_id);
        $this->assertNull($record->result);
        $this->assertStringContainsString('S7Y0OG319000HEM', $record->getRawOriginal('tracking_id'));
        $this->assertArrayNotHasKey('tracking_id', $record->toArray());
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $component->call('submit')->assertHasNoErrors();
        $this->assertDatabaseCount('personalization_requests', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tracking.example.test/api/personalization'
            && $request->method() === 'POST' && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === ['token' => 'test-token', 'tracking_id' => 'S7Y0OG319000HEM', 'type' => 'info']);
        $this->get(route('personalization'))->assertOk()->assertSee('S7Y0OG319000HEM')->assertDontSee('test-token');
    }

    public function test_in_progress_empty_identity_data_is_not_treated_as_a_result(): void
    {
        $record = PersonalizationRequest::factory()->create(['tracking_id' => 'S7Y0OG319000HEM', 'provider_id' => '12345']);
        $this->actingAs($record->user);
        $pending = $this->response();
        $pending['data'] = ['firstName' => '', 'middleName' => '', 'lastName' => '', 'idNumber' => $record->tracking_id, 'photo' => null];
        Http::fake(['https://tracking.example.test/api/personalization/check' => Http::response($pending)]);

        Livewire::test(Personalization::class)->call('checkResult', $record->reference)
            ->assertHasNoErrors()->assertSee('In-Progress')->assertSet('notice', 'In-Progress')->assertDontSee('Personalization portrait');
        $this->assertSame('in_progress', $record->fresh()->status);
        $this->assertNull($record->fresh()->result);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tracking.example.test/api/personalization/check'
            && $request->method() === 'POST' && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === ['token' => 'test-token', 'tracking_id' => 'S7Y0OG319000HEM']);
    }

    public function test_successful_checks_display_details_and_photo_and_preserve_private_data(): void
    {
        $this->freezeTime();
        $record = PersonalizationRequest::factory()->create(['tracking_id' => 'S7Y0OG319000HEM', 'provider_id' => '12345']);
        $this->actingAs($record->user);
        Http::fake(['https://tracking.example.test/api/personalization/check' => Http::response($this->response('Successful'))]);

        $component = Livewire::test(Personalization::class)->call('checkResult', $record->reference)
            ->assertHasNoErrors()->assertSee('Successful')->assertSee('ABBA')->assertSee('ALIYU')->assertSee('TILDE')
            ->assertSee('12345678901')->assertSee('1990-05-16')->assertSee('Personalization portrait');
        $this->assertSame('successful', $record->fresh()->status);
        $this->assertSame($this->response('Successful')['data'], $record->fresh()->result);
        $this->assertStringContainsString('ABBA', $record->fresh()->getRawOriginal('result'));
        $this->assertArrayNotHasKey('result', $record->toArray());
        $this->travel(10)->seconds();
        $component->call('checkResult', $record->reference)->assertHasNoErrors()->assertSet('notice', 'Successful');
        Livewire::test(Personalization::class)->call('viewResult', $record->reference)->assertSee('ABBA');
        Http::assertSentCount(2);
    }

    public function test_failed_checks_show_failure_and_can_be_refreshed(): void
    {
        $this->freezeTime();
        $record = PersonalizationRequest::factory()->create(['tracking_id' => 'S7Y0OG319000HEM', 'provider_id' => '12345']);
        $this->actingAs($record->user);
        Http::fake(['https://tracking.example.test/api/personalization/check' => Http::response($this->response('Failed'))]);

        $component = Livewire::test(Personalization::class)->call('checkResult', $record->reference)
            ->assertHasNoErrors()->assertSee('marked this personalization request as failed');
        $this->assertSame('failed', $record->fresh()->status);
        $this->travel(10)->seconds();
        $component->call('checkResult', $record->reference)->assertHasNoErrors()->assertSet('notice', 'Failed');
        Http::assertSentCount(2);
    }

    #[TestWith([''])]
    #[TestWith(['12345678901234'])]
    #[TestWith(['1234567890123456'])]
    #[TestWith(['S7Y0OG319000H M'])]
    #[TestWith(['S7Y0OG319000HE!'])]
    public function test_invalid_tracking_ids_do_not_reach_the_provider(string $trackingId): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(Personalization::class)->set('trackingId', $trackingId)->call('submit')->assertHasErrors(['trackingId']);
        $this->assertDatabaseCount('personalization_requests', 0);
        Http::assertNothingSent();
    }

    #[TestWith(['services.tracking_id.tracking_url', 'not-a-url'])]
    #[TestWith(['services.tracking_id.tracking_token', ''])]
    public function test_missing_provider_configuration_prevents_submission(string $key, string $value): void
    {
        config([$key => $value]);
        $this->actingAs(User::factory()->create());
        Livewire::test(Personalization::class)->set('trackingId', 'S7Y0OG319000HEM')->call('submit')
            ->assertHasErrors(['personalization']);
        $this->assertDatabaseCount('personalization_requests', 0);
        Http::assertNothingSent();
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function test_uncertain_submission_can_be_checked_without_resubmitting(bool $connectionFailure): void
    {
        ServicePrice::factory()->create(['service' => 'personalization', 'method' => 'tracking', 'amount' => 0]);
        $this->freezeTime();
        $this->actingAs(User::factory()->create());
        Http::fake([
            'https://tracking.example.test/api/personalization' => $connectionFailure ? Http::failedConnection() : Http::response('Server error', 503),
            'https://tracking.example.test/api/personalization/check' => Http::response($this->response()),
        ]);

        $component = Livewire::test(Personalization::class)->set('trackingId', 'S7Y0OG319000HEM')->call('submit')
            ->assertHasErrors(['personalization'])->assertSee('Pending')->assertSet('selectedReference', '');
        $record = PersonalizationRequest::query()->sole();
        $this->assertSame('pending', $record->status);
        $component->call('submit')->assertHasErrors(['personalization']);
        Http::assertSentCount(1);
        $this->travel(10)->seconds();
        $component->call('checkResult', $record->reference)->assertHasNoErrors()->assertSee('In-Progress');
        $this->assertSame('in_progress', $record->fresh()->status);
        $this->assertDatabaseCount('personalization_requests', 1);
    }

    #[TestWith(['success', false])]
    #[TestWith(['tracking_id', 'OTHERTRACKING001'])]
    #[TestWith(['id', 999])]
    #[TestWith(['status', 'Unknown'])]
    #[TestWith(['data', ['idNumber' => 'S7Y0OG319000HEM', 'firstName' => '', 'lastName' => '']])]
    public function test_invalid_checks_preserve_the_pending_status(string $key, mixed $value): void
    {
        $record = PersonalizationRequest::factory()->create(['tracking_id' => 'S7Y0OG319000HEM', 'provider_id' => '12345']);
        $this->actingAs($record->user);
        Http::fake(['https://tracking.example.test/api/personalization/check' => Http::response([...$this->response('Successful'), $key => $value])]);

        Livewire::test(Personalization::class)->call('checkResult', $record->reference)
            ->assertHasErrors(['personalization']);
        $this->assertSame('in_progress', $record->fresh()->status);
        $this->assertNull($record->fresh()->result);
    }

    public function test_checks_are_throttled_and_can_resume_after_ten_seconds(): void
    {
        $this->freezeTime();
        $record = PersonalizationRequest::factory()->create(['tracking_id' => 'S7Y0OG319000HEM', 'provider_id' => '12345']);
        $this->actingAs($record->user);
        Http::fake(['https://tracking.example.test/api/personalization/check' => Http::sequence()->push($this->response())->push($this->response('Successful'))]);
        $component = Livewire::test(Personalization::class)->call('checkResult', $record->reference)->assertHasNoErrors();
        $component->call('checkResult', $record->reference)->assertHasErrors(['personalization']);
        Http::assertSentCount(1);
        $this->travel(10)->seconds();
        $component->call('checkResult', $record->reference)->assertHasNoErrors()->assertSee('ABBA');
        Http::assertSentCount(2);
    }

    public function test_other_users_cannot_check_or_view_private_requests(): void
    {
        $record = PersonalizationRequest::factory()->create(['tracking_id' => 'S7Y0OG319000HEM', 'status' => 'successful', 'result' => $this->response('Successful')['data']]);
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(Personalization::class)->assertDontSee($record->tracking_id)->assertDontSee('ABBA');
        $component->call('checkResult', $record->reference)->assertNotFound();
        Livewire::test(Personalization::class)->call('viewResult', $record->reference)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_provider_text_is_escaped_and_unsafe_photos_are_not_displayed(): void
    {
        $record = PersonalizationRequest::factory()->create(['status' => 'successful', 'result' => [
            'firstName' => '<script>alert(1)</script>', 'photo' => 'javascript:alert(1)',
        ]]);
        $this->actingAs($record->user);
        Livewire::test(Personalization::class)->call('viewResult', $record->reference)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('src="javascript:', false);
    }

    public function test_inactive_or_signed_out_users_cannot_submit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $component = Livewire::test(Personalization::class)->set('trackingId', 'S7Y0OG319000HEM');
        $user->forceFill(['is_active' => false])->save();
        $component->call('submit')->assertForbidden();
        $this->assertDatabaseCount('personalization_requests', 0);
        Auth::logout();
        Livewire::test(Personalization::class)->assertForbidden();
        Http::assertNothingSent();
    }

    #[TestWith(['123456789012345'])]
    #[TestWith(['s7y0og319000hem'])]
    public function test_valid_numeric_and_alphanumeric_tracking_ids_preserve_their_characters(string $trackingId): void
    {
        ServicePrice::factory()->create(['service' => 'personalization', 'method' => 'tracking', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        Http::fake(['https://tracking.example.test/api/personalization' => Http::response([...$this->response(), 'tracking_id' => $trackingId])]);

        Livewire::test(Personalization::class)->set('trackingId', $trackingId)->call('submit')->assertHasNoErrors();
        $this->assertSame($trackingId, PersonalizationRequest::query()->sole()->tracking_id);
        Http::assertSent(fn (Request $request): bool => $request['tracking_id'] === $trackingId);
    }

    #[TestWith(['In-Progress'])]
    #[TestWith(['Successful'])]
    #[TestWith(['Failed'])]
    public function test_submission_only_sets_provider_status_and_results_are_displayed_after_manual_check(string $status): void
    {
        ServicePrice::factory()->create(['service' => 'personalization', 'method' => 'tracking', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        $ack = $this->response($status);
        unset($ack['data']);
        Http::fake([
            'https://tracking.example.test/api/personalization' => function (Request $request) use ($ack) {
                $this->assertSame('pending', PersonalizationRequest::query()->sole()->status);

                return Http::response($ack);
            },
            'https://tracking.example.test/api/personalization/check' => Http::response($this->response($status)),
        ]);

        $component = Livewire::test(Personalization::class)->set('trackingId', 'S7Y0OG319000HEM')->call('submit')
            ->assertHasNoErrors()->assertSet('notice', 'submission successful')->assertSet('selectedReference', '')
            ->assertSee($status)->assertDontSee('id="personalization-result-heading"', false);
        $record = PersonalizationRequest::query()->sole();
        $this->assertSame($status, $record->provider_status);
        $this->assertNull($record->result);
        $this->assertNull($record->last_checked_at);
        $component->call('submit')->assertHasNoErrors();
        Http::assertSentCount(1);
        $component->call('checkResult', $record->reference)->assertHasNoErrors()
            ->assertSet('notice', $status)->assertSee('id="personalization-result-heading"', false);
        Http::assertSentCount(2);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->url() === 'https://tracking.example.test/api/personalization'));
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->url() === 'https://tracking.example.test/api/personalization/check'));
    }

    public function test_unclear_submission_stays_pending_without_an_automatic_status_check(): void
    {
        ServicePrice::factory()->create(['service' => 'personalization', 'method' => 'tracking', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        Http::fake(['https://tracking.example.test/api/personalization' => Http::response(['success' => true, 'message' => 'Saved'])]);

        Livewire::test(Personalization::class)->set('trackingId', 'S7Y0OG319000HEM')->call('submit')
            ->assertHasErrors(['personalization'])->assertSet('selectedReference', '')->assertSee('Pending');
        $record = PersonalizationRequest::query()->sole();
        $this->assertSame('pending', $record->status);
        $this->assertNull($record->provider_status);
        $this->assertNull($record->last_checked_at);
        Http::assertSentCount(1);
    }

    public function test_numeric_string_provider_ids_are_accepted(): void
    {
        ServicePrice::factory()->create(['service' => 'personalization', 'method' => 'tracking', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        Http::fake(['https://tracking.example.test/api/personalization' => Http::response([...$this->response(), 'id' => '12345'])]);

        Livewire::test(Personalization::class)->set('trackingId', 'S7Y0OG319000HEM')->call('submit')
            ->assertHasNoErrors()->assertSee('In-Progress');
        $this->assertSame('12345', PersonalizationRequest::query()->sole()->provider_id);
        Http::assertSentCount(1);
    }

    public function test_checking_a_completed_request_refreshes_a_missing_photo_and_displays_the_returned_status(): void
    {
        $record = PersonalizationRequest::factory()->create([
            'tracking_id' => 'S7Y0OG319000HEM', 'provider_id' => '12345',
            'status' => 'successful', 'result' => [...$this->response('Successful')['data'], 'photo' => null],
        ]);
        $this->actingAs($record->user);
        Http::fake(['https://tracking.example.test/api/personalization/check' => Http::response($this->response('Successful'))]);

        Livewire::test(Personalization::class)->call('checkResult', $record->reference)->assertHasNoErrors()
            ->assertSet('notice', 'Successful')->assertSee('Personalization portrait');
        $this->assertSame('Successful', $record->fresh()->provider_status);
        $this->assertNotNull($record->fresh()->image('photo'));
        Http::assertSentCount(1);
    }

    private function response(string $status = 'In-Progress'): array
    {
        return [
            'success' => true, 'id' => 12345, 'tracking_id' => 'S7Y0OG319000HEM', 'status' => $status,
            ...($status === 'Successful' ? ['data' => [
                'firstName' => 'ABBA', 'middleName' => 'ALIYU', 'lastName' => 'TILDE',
                'dateOfBirth' => '1990-05-16', 'gender' => 'M', 'idNumber' => '12345678901', 'tracking_id' => 'S7Y0OG319000HEM',
                'photo' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lfoAAAAASUVORK5CYII=',
            ]] : []),
        ];
    }
}
