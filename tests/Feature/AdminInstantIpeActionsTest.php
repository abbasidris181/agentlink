<?php

namespace Tests\Feature;

use App\InstantIpeService;
use App\Jobs\CheckInstantIpeStatus;
use App\Jobs\SubmitInstantIpeRequest;
use App\Livewire\Admin\Transactions;
use App\Livewire\InstantIpe;
use App\Models\AdminAudit;
use App\Models\InstantIpeRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Money;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AdminInstantIpeActionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_instant_ipe_table_shows_request_reply_and_all_three_actions(): void
    {
        $record = $this->paidRequest();
        $record->update(['reply' => 'Existing reply', 'nin' => '01234567890']);
        $this->actingAs(User::factory()->administrator()->create());

        $this->get(route('admin.transactions', ['transactionGroup' => 'instant-ipe']))->assertOk()
            ->assertSee('Action')->assertSee('Reply')->assertSee('Refund')->assertSee('Complete')
            ->assertSee($record->tracking_id)->assertSee('Existing reply')->assertSee('01234567890');
    }

    public function test_refund_returns_the_original_charge_once_and_stops_provider_work(): void
    {
        Http::preventStrayRequests();
        $record = $this->paidRequest();
        $actor = User::factory()->administrator()->create();
        $this->actingAs($actor);

        Livewire::test(Transactions::class, ['transactionGroup' => 'instant-ipe'])->call('refund', $record->id)->assertHasNoErrors()->assertSee('Refunded')
            ->call('refund', $record->id)->assertHasNoErrors();

        $record->refresh();
        $this->assertSame(50000, $record->user->wallet_balance);
        $this->assertNotNull($record->refunded_at);
        $this->assertNull($record->next_check_at);
        $refund = $record->walletTransaction->refund()->sole();
        $this->assertSame(12500, $refund->amount);
        $this->assertSame($actor->id, $refund->actor_id);
        $this->assertSame(1, AdminAudit::query()->where('event', 'instant_ipe.refunded')->count());
        $this->assertDatabaseHas('wallet_transactions', ['id' => $refund->id, 'old_balance' => '375.00', 'new_balance' => '500.00']);
        (new SubmitInstantIpeRequest($record->id))->handle(app(InstantIpeService::class));
        app(InstantIpeService::class)->checkRequest($record->id);
        Http::assertNothingSent();
    }

    public function test_reply_is_visible_to_user_and_provider_results_cannot_overwrite_it(): void
    {
        $record = $this->paidRequest();
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Transactions::class, ['transactionGroup' => 'instant-ipe'])->call('editReply', $record->id)
            ->set('reply', '  Your clearance has been reviewed.  ')->call('saveReply')->assertHasNoErrors()
            ->assertSet('replyRequestId', null)->assertSee('Your clearance has been reviewed.');
        $this->assertNotNull($record->fresh()->admin_replied_at);
        config(['services.tracking_id.tracking_url' => 'https://ipe.example.test', 'services.tracking_id.tracking_token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://ipe.example.test/api/clearance/status' => Http::response(['status' => 'Successful', 'reply' => 'Provider reply', 'nin' => '01234567890'])]);

        app(InstantIpeService::class)->checkRequest($record->id);

        $this->assertSame('Your clearance has been reviewed.', $record->fresh()->reply);
        $this->assertSame('successful', $record->fresh()->status);
        $this->assertSame('01234567890', $record->fresh()->nin);
        $this->actingAs($record->user);
        Livewire::test(InstantIpe::class)->assertSee('Your clearance has been reviewed.')->assertSee('01234567890');
        Http::assertSentCount(1);
        $this->assertDatabaseHas('admin_audits', ['user_id' => $record->user_id, 'event' => 'instant_ipe.replied']);
    }

    #[TestWith(['queued'])]
    #[TestWith(['pending'])]
    #[TestWith(['unconfirmed'])]
    public function test_complete_preserves_reply_and_payment_and_stops_queued_work(string $status): void
    {
        Http::preventStrayRequests();
        $record = $this->paidRequest($status);
        $record->update(['reply' => 'Completed manually', 'nin' => '01234567890']);
        $due = $record->next_check_at->timestamp;
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Transactions::class, ['transactionGroup' => 'instant-ipe'])->call('complete', $record->id)->assertHasNoErrors()->assertSee('Completed')
            ->call('complete', $record->id)->assertHasNoErrors();

        $record->refresh();
        $this->assertSame('successful', $record->status);
        $this->assertSame('Completed manually', $record->reply);
        $this->assertSame('01234567890', $record->nin);
        $this->assertNull($record->next_check_at);
        $this->assertSame(37500, $record->user->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame(1, AdminAudit::query()->where('event', 'instant_ipe.completed')->count());
        (new SubmitInstantIpeRequest($record->id))->handle(app(InstantIpeService::class));
        (new CheckInstantIpeStatus($record->id, $due))->handle(app(InstantIpeService::class));
        Http::assertNothingSent();
    }

    #[TestWith(['refund'])]
    #[TestWith(['complete'])]
    public function test_an_in_flight_provider_response_cannot_override_an_admin_final_action(string $action): void
    {
        $record = $this->paidRequest();
        $actor = User::factory()->administrator()->create();
        config(['services.tracking_id.tracking_url' => 'https://ipe.example.test', 'services.tracking_id.tracking_token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['https://ipe.example.test/api/clearance/status' => function () use ($action, $actor, $record) {
            $service = app(InstantIpeService::class);
            if ($action === 'refund') {
                $service->refundRequest($actor, $record->id);
            } else {
                $service->completeRequest($actor, $record->id);
            }

            return Http::response(['status' => $action === 'refund' ? 'Successful' : 'Failed', 'reply' => 'Late response', 'nin' => null]);
        }]);

        app(InstantIpeService::class)->checkRequest($record->id);

        $this->assertSame($action === 'refund' ? 'failed' : 'successful', $record->fresh()->status);
        $this->assertSame($action === 'refund' ? 50000 : 37500, $record->user->fresh()->wallet_balance);
        $this->assertNull($record->fresh()->next_check_at);
        Http::assertSentCount(1);
    }

    #[TestWith(['refund'])]
    #[TestWith(['editReply'])]
    #[TestWith(['complete'])]
    public function test_read_only_staff_cannot_use_request_actions(string $action): void
    {
        $record = $this->paidRequest();
        $this->actingAs(User::factory()->staff(['transactions.view'])->create());
        Livewire::test(Transactions::class, ['transactionGroup' => 'instant-ipe'])->call($action, $record->id)->assertForbidden();
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertSame(37500, $record->user->wallet_balance);
    }

    public function test_staff_can_use_only_their_granted_request_permissions_and_revocation_is_enforced(): void
    {
        $record = $this->paidRequest();
        $staff = User::factory()->staff(['transactions.view', 'requests.manage'])->create();
        $this->actingAs($staff);
        $form = Livewire::test(Transactions::class, ['transactionGroup' => 'instant-ipe'])->call('editReply', $record->id)
            ->set('reply', 'Staff reply')->call('saveReply')->assertHasNoErrors();
        Livewire::test(Transactions::class, ['transactionGroup' => 'instant-ipe'])->call('refund', $record->id)->assertForbidden();
        $staff->staff_permissions = ['transactions.view'];
        $staff->save();
        $form->call('complete', $record->id)->assertForbidden();
        $refundStaff = User::factory()->staff(['transactions.view', 'requests.refund'])->create();
        $this->actingAs($refundStaff);
        Livewire::test(Transactions::class, ['transactionGroup' => 'instant-ipe'])->call('refund', $record->id)->assertHasNoErrors();
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    public function test_empty_replies_are_rejected(string $reply): void
    {
        $record = $this->paidRequest();
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Transactions::class, ['transactionGroup' => 'instant-ipe'])->call('editReply', $record->id)
            ->set('reply', $reply)->call('saveReply')->assertHasErrors('reply');
        $this->assertNull($record->fresh()->reply);
    }

    public function test_wallet_capacity_error_rolls_back_the_refund(): void
    {
        $record = $this->paidRequest();
        $owner = $record->user;
        $owner->wallet_balance = Money::MAX_MINOR;
        $owner->save();
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Transactions::class, ['transactionGroup' => 'instant-ipe'])->call('refund', $record->id)->assertHasErrors('refund');
        $this->assertNull($record->fresh()->refunded_at);
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertSame(Money::MAX_MINOR, $owner->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_refunded_request_cannot_be_completed(): void
    {
        $record = $this->paidRequest();
        $actor = User::factory()->administrator()->create();
        $this->actingAs($actor);
        Livewire::test(Transactions::class, ['transactionGroup' => 'instant-ipe'])->call('refund', $record->id)
            ->call('complete', $record->id)->assertHasErrors('complete');
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertNotNull($record->fresh()->refunded_at);
    }

    public function test_actions_cannot_be_called_from_another_transaction_group(): void
    {
        $record = $this->paidRequest();
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Transactions::class, ['transactionGroup' => 'bvn'])->call('refund', $record->id)->assertNotFound();
        $this->assertNull($record->fresh()->refunded_at);
    }

    public function test_refund_audit_failure_rolls_back_the_balance_and_request_changes(): void
    {
        $record = $this->paidRequest();
        $actor = User::factory()->administrator()->create();
        AdminAudit::creating(function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            app(InstantIpeService::class)->refundRequest($actor, $record->id);
            $this->fail('Refund saved without its audit entry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            AdminAudit::flushEventListeners();
        }
        $this->assertSame(37500, $record->user->fresh()->wallet_balance);
        $this->assertNull($record->fresh()->refunded_at);
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_zero_price_requests_cannot_create_a_refund_credit(): void
    {
        $record = $this->paidRequest();
        $charge = $record->walletTransaction;
        $charge->amount = 0;
        $charge->save();
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Transactions::class, ['transactionGroup' => 'instant-ipe'])->call('refund', $record->id)->assertHasErrors('refund');
        $this->assertSame(37500, $record->user->fresh()->wallet_balance);
        $this->assertNull($record->fresh()->refunded_at);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    private function paidRequest(string $status = 'pending'): InstantIpeRequest
    {
        $owner = User::factory()->create(['wallet_balance' => 37500]);
        $record = InstantIpeRequest::factory()->create(['user_id' => $owner->id, 'price' => 12500, 'status' => $status, 'dispatch_started_at' => $status === 'queued' ? null : now()->subMinutes(2), 'next_check_at' => now()->addMinutes(25)]);
        WalletTransaction::factory()->create(['instant_ipe_request_id' => $record->id, 'reference' => $record->reference, 'user_id' => $owner->id, 'actor_id' => $owner->id, 'service' => 'Instant IPE', 'type' => 'service_debit', 'amount' => 12500, 'old_balance' => 50000, 'new_balance' => 37500]);

        return $record;
    }
}
