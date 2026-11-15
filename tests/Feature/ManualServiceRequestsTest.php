<?php

namespace Tests\Feature;

use App\Livewire\Admin\Requests;
use App\Livewire\BvnRetrieval;
use App\Livewire\Delinking;
use App\Livewire\IpeRequest;
use App\Livewire\NinValidation;
use App\Models\AdminAudit;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\Models\SiteSetting;
use App\Models\User;
use App\ServiceAvailability;
use App\SubmitServiceRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ManualServiceRequestsTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{string, class-string, array<string, string>, array<string, string>, string}> */
    public static function serviceForms(): array
    {
        return [
            'validations' => ['validations', NinValidation::class, [], ['nin' => '01234567890'], 'nin'],
            'delinking' => ['delinking', Delinking::class, [], ['nin' => '01234567890'], 'nin'],
            'ipe clearing' => ['ipe-clearing', IpeRequest::class, ['service' => 'ipe-clearing'], ['trackingId' => 'ABC123DEF456GH7'], 'tracking'],
            'mod ipe' => ['mod-ipe', IpeRequest::class, ['service' => 'mod-ipe'], ['trackingId' => 'ABC123DEF456GH7'], 'tracking'],
            'bvn phone' => ['bvn-retrieval', BvnRetrieval::class, [], ['phone' => '08012345678', 'fullName' => 'Taylor Example'], 'phone'],
        ];
    }

    #[DataProvider('serviceForms')]
    public function test_submissions_are_collected_for_admin_and_visible_only_in_the_owners_history(string $service, string $component, array $parameters, array $inputs, string $method): void
    {
        Http::preventStrayRequests();
        $owner = User::factory()->create(['wallet_balance' => 50000]);
        ServicePrice::factory()->create(['service' => $service, 'method' => $method, 'amount' => 12500]);
        $this->actingAs($owner);
        $form = Livewire::test($component, $parameters);
        foreach ($inputs as $field => $value) {
            $form->set($field, $value);
        }
        $form->call('submit')->assertHasNoErrors()->assertSee('Request submitted')->assertSee('Pending');
        $record = ServiceRequest::query()->sole();
        $this->assertSame($owner->id, $record->user_id);
        $this->assertSame($service, $record->service);
        $this->assertSame($method, $record->method);
        $this->assertSame(12500, $record->quoted_price);
        $this->assertSame(37500, $owner->fresh()->wallet_balance);
        $form->assertSee($record->reference);
        $this->assertDatabaseHas('wallet_transactions', ['service_request_id' => $record->id, 'reference' => $record->reference, 'user_id' => $owner->id, 'amount' => '125.00', 'old_balance' => '500.00', 'new_balance' => '375.00']);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->get(route('transactions'))->assertOk()->assertSee($record->reference);
        $this->assertDatabaseHas('admin_audits', ['event' => 'service_request.submitted', 'actor_id' => $owner->id]);
        $this->actingAs(User::factory()->create());
        Livewire::test($component, $parameters)->assertDontSee($record->reference);
        $this->actingAs(User::factory()->administrator()->create());
        $this->get(route('admin.requests'))->assertOk()->assertSee($record->reference)->assertSee($owner->email);
        $this->get(route('admin.requests.show', ['requestId' => $record->id]))->assertOk()->assertSee($record->reference)->assertSee(array_values($inputs)[0]);
        Http::assertNothingSent();
    }

    public function test_crm_proof_is_kept_private_and_accessible_only_to_owner_or_authorized_admin(): void
    {
        ServicePrice::factory()->create(['service' => 'bvn-retrieval', 'method' => 'crm', 'amount' => 0]);
        Storage::fake('local');
        $owner = User::factory()->create();
        $record = app(SubmitServiceRequest::class)->create($owner, 'bvn-retrieval', 'crm', ['agent_code' => 'AG001', 'ticket_id' => 'TKT001', 'bms_ticket' => 'BMS001'], (string) Str::uuid(), UploadedFile::fake()->image('proof.png'));
        Storage::disk('local')->assertExists($record->screenshot_path);
        $this->assertStringStartsWith('service-request-proofs/'.$owner->id.'/', $record->screenshot_path);
        $url = route('service-requests.proof', $record);
        $this->get($url)->assertRedirect(route('home'));
        $this->actingAs($owner)->get($url)->assertOk()->assertDownload('proof-'.$record->reference.'.png');
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs(User::factory()->staff()->create())->get($url)->assertNotFound();
        $this->actingAs(User::factory()->staff(['requests.view'])->create())->get($url)->assertOk();
        $this->get(route('admin.requests.show', ['requestId' => $record->id]))->assertOk()->assertSee('AG001')->assertSee('TKT001')->assertSee('BMS001')->assertSee('Download screenshot proof');
    }

    public function test_input_and_replies_are_plain_text_but_not_serialized(): void
    {
        $record = ServiceRequest::factory()->create(['payload' => ['nin' => '01234567890'], 'reply' => 'Sensitive reply example']);
        $stored = DB::table('service_requests')->where('id', $record->id)->first();
        $this->assertSame(['nin' => '01234567890'], json_decode($stored->payload, true));
        $this->assertSame('Sensitive reply example', $stored->reply);
        $this->assertSame('01234567890', $record->fresh()->payload['nin']);
        $this->assertArrayNotHasKey('payload', $record->toArray());
        $this->assertArrayNotHasKey('reply', $record->toArray());
        $this->assertArrayNotHasKey('screenshot_path', $record->toArray());
    }

    #[TestWith(['users'])]
    #[TestWith(['agent'])]
    #[TestWith(['staff'])]
    public function test_new_admin_section_is_denied_without_explicit_permission(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]));
        $this->get(route('admin.requests'))->assertForbidden();
        Livewire::test(Requests::class)->assertForbidden();
    }

    public function test_read_only_staff_can_inspect_requests_but_cannot_change_status_or_reply(): void
    {
        $record = ServiceRequest::factory()->create();
        $this->actingAs(User::factory()->staff(['requests.view'])->create());
        Livewire::test(Requests::class, ['requestId' => $record->id])->assertSet('reply', '')->assertDontSee('Save status and reply')
            ->set('status', 'completed')->set('reply', 'Unauthorized')->call('save')->assertForbidden();
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertNull($record->fresh()->reply);
    }

    public function test_authorized_staff_can_reply_and_the_owner_sees_the_new_status_and_reply(): void
    {
        $record = ServiceRequest::factory()->create();
        $staff = User::factory()->staff(['requests.manage'])->create();
        $this->assertContains('requests.view', $staff->staff_permissions);
        $this->actingAs($staff);
        Livewire::test(Requests::class, ['requestId' => $record->id])->set('status', 'completed')->set('reply', 'Completed successfully. Please check your record.')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('completed', $record->fresh()->status);
        $this->assertSame($staff->id, $record->fresh()->handled_by);
        $this->assertDatabaseHas('admin_audits', ['actor_id' => $staff->id, 'user_id' => $record->user_id, 'event' => 'service_request.updated']);
        $this->actingAs($record->user);
        Livewire::test(NinValidation::class)->assertSee($record->reference)->assertSee('Completed')->assertSee('Completed successfully. Please check your record.');
        $this->actingAs(User::factory()->create());
        Livewire::test(NinValidation::class)->assertDontSee($record->reference)->assertDontSee('Completed successfully.');
    }

    #[TestWith(['completed'])]
    #[TestWith(['rejected'])]
    public function test_terminal_status_requires_a_reply(string $status): void
    {
        $record = ServiceRequest::factory()->create();
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Requests::class, ['requestId' => $record->id])->set('status', $status)->set('reply', ' ')->call('save')->assertHasErrors('reply');
        $this->assertSame('pending', $record->fresh()->status);
    }

    public function test_status_and_reply_validation_and_stale_updates_do_not_overwrite_work(): void
    {
        $record = ServiceRequest::factory()->create();
        $this->actingAs(User::factory()->administrator()->create());
        $first = Livewire::test(Requests::class, ['requestId' => $record->id]);
        $second = Livewire::test(Requests::class, ['requestId' => $record->id]);
        $first->set('status', 'invalid')->set('reply', str_repeat('x', 5001))->call('save')->assertHasErrors(['status', 'reply']);
        $first->set('status', 'in_progress')->set('reply', 'Review started')->call('save')->assertHasNoErrors();
        $second->set('status', 'completed')->set('reply', 'Stale overwrite')->call('save')->assertHasErrors('status');
        $this->assertSame('in_progress', $record->fresh()->status);
        $this->assertSame('Review started', $record->fresh()->reply);
    }

    public function test_revoked_staff_permission_blocks_an_already_open_request(): void
    {
        $record = ServiceRequest::factory()->create();
        $staff = User::factory()->staff(['requests.manage'])->create();
        $this->actingAs($staff);
        $form = Livewire::test(Requests::class, ['requestId' => $record->id]);
        $staff->staff_permissions = ['requests.view'];
        $staff->save();
        $form->set('reply', 'Not allowed')->call('save')->assertForbidden();
        $this->assertSame('pending', $record->fresh()->status);
    }

    public function test_admin_queue_can_be_filtered_by_service_status_and_customer(): void
    {
        $record = ServiceRequest::factory()->create(['status' => 'in_progress']);
        $other = ServiceRequest::factory()->create(['service' => 'delinking']);
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Requests::class)->assertSee($record->reference)->assertSee($other->reference)
            ->set('serviceFilter', 'validations')->assertSee($record->reference)->assertDontSee($other->reference)
            ->set('statusFilter', 'completed')->assertDontSee($record->reference)
            ->set('statusFilter', 'all')->set('serviceFilter', 'all')->set('search', $other->user->email)
            ->assertSee($other->reference)->assertDontSee($record->reference);
    }

    public function test_repeat_submission_uses_one_reference_and_charges_the_original_price_once(): void
    {
        $owner = User::factory()->create(['wallet_balance' => 25000]);
        $price = ServicePrice::factory()->create(['service' => 'validations', 'method' => 'nin', 'amount' => 25000]);
        $key = (string) Str::uuid();
        $action = app(SubmitServiceRequest::class);
        $first = $action->create($owner, 'validations', 'nin', ['nin' => '01234567890'], $key);
        $price->update(['amount' => 30000]);
        $repeat = $action->create($owner, 'validations', 'nin', ['nin' => '01234567890'], $key);
        $this->assertSame($first->id, $repeat->id);
        $this->assertSame(25000, $repeat->quoted_price);
        $this->assertSame(0, $owner->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('admin_audits', 1);
        try {
            $action->create($owner, 'validations', 'nin', ['nin' => '12345678901'], $key);
            $this->fail('Changed repeated request was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('serviceAvailability', $exception->errors());
        }
        $this->assertDatabaseCount('service_requests', 1);
    }

    public function test_duplicate_crm_submission_does_not_store_the_proof_twice(): void
    {
        ServicePrice::factory()->create(['service' => 'bvn-retrieval', 'method' => 'crm', 'amount' => 0]);
        Storage::fake('local');
        $owner = User::factory()->create();
        $proof = UploadedFile::fake()->image('proof.png');
        $key = (string) Str::uuid();
        $action = app(SubmitServiceRequest::class);
        $input = ['agent_code' => 'A1', 'ticket_id' => 'T1', 'bms_ticket' => 'B1'];
        $first = $action->create($owner, 'bvn-retrieval', 'crm', $input, $key, $proof);
        $again = $action->create($owner, 'bvn-retrieval', 'crm', $input, $key, $proof);
        $this->assertSame($first->id, $again->id);
        $this->assertCount(1, Storage::disk('local')->allFiles('service-request-proofs'));
    }

    public function test_request_and_private_proof_are_removed_if_auditing_fails(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['wallet_balance' => 50000]);
        ServicePrice::factory()->create(['service' => 'bvn-retrieval', 'method' => 'crm', 'amount' => 12500]);
        AdminAudit::creating(function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            app(SubmitServiceRequest::class)->create($owner, 'bvn-retrieval', 'crm', ['agent_code' => 'A1', 'ticket_id' => 'T1', 'bms_ticket' => 'B1'], (string) Str::uuid(), UploadedFile::fake()->image('proof.png'));
            $this->fail('A request was committed without its audit.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            AdminAudit::flushEventListeners();
        }
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('service-request-proofs'));
        $this->assertSame(50000, $owner->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    #[DataProvider('serviceForms')]
    public function test_disabled_services_and_invalid_forms_cannot_enter_the_admin_queue(string $service, string $component, array $parameters, array $inputs, string $method): void
    {
        $this->actingAs(User::factory()->create());
        $form = Livewire::test($component, $parameters)->call('submit')->assertHasErrors();
        foreach ($inputs as $field => $value) {
            $form->set($field, $value);
        }
        SiteSetting::create(['key' => ServiceAvailability::settingKey($service), 'value' => '1']);
        $form->call('submit')->assertHasErrors('serviceAvailability');
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_status_reply_and_revision_roll_back_if_the_update_audit_fails(): void
    {
        $record = ServiceRequest::factory()->create();
        $this->actingAs(User::factory()->administrator()->create());
        $form = Livewire::test(Requests::class, ['requestId' => $record->id])->set('status', 'completed')->set('reply', 'Done');
        AdminAudit::creating(function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            $form->call('save');
            $this->fail('A status update was committed without its audit.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('Audit unavailable', $exception->getMessage());
        } finally {
            AdminAudit::flushEventListeners();
        }
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertNull($record->fresh()->reply);
        $this->assertSame(0, $record->fresh()->revision);
    }

    public function test_admin_replies_are_escaped_in_customer_history(): void
    {
        $record = ServiceRequest::factory()->create(['reply' => '<script>alert("unsafe")</script>']);
        $this->actingAs($record->user);
        Livewire::test(NinValidation::class)->assertSee('<script>alert("unsafe")</script>')->assertDontSee('<script>alert("unsafe")</script>', false);
    }

    #[TestWith([null])]
    #[TestWith([50001])]
    public function test_missing_price_or_insufficient_balance_prevents_submission(?int $price): void
    {
        $owner = User::factory()->create(['wallet_balance' => 50000]);
        if ($price !== null) {
            ServicePrice::factory()->create(['service' => 'validations', 'method' => 'nin', 'amount' => $price]);
        }
        $this->actingAs($owner);
        Livewire::test(NinValidation::class)->set('nin', '01234567890')->call('submit')->assertHasErrors('serviceAvailability');
        $this->assertSame(50000, $owner->fresh()->wallet_balance);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('admin_audits', 0);
    }

    public function test_explicit_zero_price_records_a_free_submission_without_changing_balance(): void
    {
        ServicePrice::factory()->create(['service' => 'validations', 'method' => 'nin', 'amount' => 0]);
        $owner = User::factory()->create(['wallet_balance' => 0]);
        $record = app(SubmitServiceRequest::class)->create($owner, 'validations', 'nin', ['nin' => '01234567890'], (string) Str::uuid());
        $this->assertSame(0, $owner->fresh()->wallet_balance);
        $this->assertSame(0, $record->walletTransaction->amount);
        $this->assertSame(0, $record->quoted_price);
        $this->assertDatabaseCount('service_requests', 1);
    }
}
