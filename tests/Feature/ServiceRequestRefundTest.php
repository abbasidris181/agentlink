<?php

namespace Tests\Feature;

use App\Livewire\Admin\Requests;
use App\Livewire\NinValidation;
use App\Models\AdminAudit;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Money;
use App\RefundServiceRequest;
use App\SubmitServiceRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ServiceRequestRefundTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_admin_table_shows_all_user_inputs_and_private_proof_links(): void
    {
        $records = [
            ServiceRequest::factory()->create(['service' => 'validations', 'method' => 'nin', 'payload' => ['nin' => '01234567890']]),
            ServiceRequest::factory()->create(['service' => 'delinking', 'method' => 'nin', 'payload' => ['nin' => '12345678901']]),
            ServiceRequest::factory()->create(['service' => 'ipe-clearing', 'method' => 'tracking', 'payload' => ['tracking_id' => 'ABC123DEF456GH7']]),
            ServiceRequest::factory()->create(['service' => 'mod-ipe', 'method' => 'tracking', 'payload' => ['tracking_id' => 'XYZ123DEF456GH7']]),
            ServiceRequest::factory()->create(['service' => 'bvn-retrieval', 'method' => 'phone', 'payload' => ['phone' => '08012345678', 'full_name' => 'Taylor Example']]),
            ServiceRequest::factory()->create(['service' => 'bvn-retrieval', 'method' => 'crm', 'payload' => ['agent_code' => 'AG001', 'ticket_id' => 'TKT001', 'bms_ticket' => 'BMS001'], 'screenshot_path' => 'private/proof.png']),
        ];
        $this->actingAs(User::factory()->administrator()->create());
        $page = $this->get(route('admin.requests'))->assertOk()->assertSee('Submitted inputs')->assertSee('Reply to user');
        foreach ($records as $record) {
            foreach ($record->inputDetails() as $value) {
                $page->assertSee($value);
            }
        }
        $page->assertSee(route('service-requests.proof', $records[5]), false);
    }

    public function test_table_reply_is_saved_and_visible_in_the_customer_history(): void
    {
        $record = ServiceRequest::factory()->create();
        $this->actingAs(User::factory()->staff(['requests.manage'])->create());
        Livewire::test(Requests::class)->set('replies.'.$record->id, 'Your request is being reviewed.')
            ->call('saveReply', $record->id, 0)->assertHasNoErrors()->assertSee('Your request is being reviewed.');
        $this->assertSame('Your request is being reviewed.', $record->fresh()->reply);
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertDatabaseHas('admin_audits', ['event' => 'service_request.replied']);
        $this->actingAs($record->user);
        Livewire::test(NinValidation::class)->assertSee('Your request is being reviewed.');
    }

    public function test_table_replies_require_permission_valid_text_and_the_current_revision(): void
    {
        $record = ServiceRequest::factory()->create();
        $this->actingAs(User::factory()->staff(['requests.view'])->create());
        Livewire::test(Requests::class)->set('replies.'.$record->id, 'Unauthorized')->call('saveReply', $record->id, 0)->assertForbidden();
        $this->actingAs(User::factory()->staff(['requests.manage'])->create());
        $form = Livewire::test(Requests::class);
        $form->set('replies.'.$record->id, ' ')->call('saveReply', $record->id, 0)->assertHasErrors('replies.'.$record->id);
        $form->set('replies.'.$record->id, str_repeat('x', 5001))->call('saveReply', $record->id, 0)->assertHasErrors('replies.'.$record->id);
        $record->update(['revision' => 1, 'reply' => 'Latest reply']);
        $form->set('replies.'.$record->id, 'Stale reply')->call('saveReply', $record->id, 0)->assertHasErrors('replies.'.$record->id);
        $this->assertSame('Latest reply', $record->fresh()->reply);
    }

    public function test_refund_returns_the_original_payment_once_and_records_both_histories(): void
    {
        $record = $this->paidRequest();
        ServicePrice::query()->update(['amount' => 90000]);
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);
        $form = Livewire::test(Requests::class)->assertSee('Refund 125.00')->call('refund', $record->id)->assertHasNoErrors()->assertSee('Refunded 125.00');
        $refund = $record->walletTransaction->refund()->sole();
        $this->assertSame(50000, $record->user->fresh()->wallet_balance);
        $this->assertSame(12500, $refund->amount);
        $this->assertSame(37500, $refund->old_balance);
        $this->assertSame(50000, $refund->new_balance);
        $this->assertSame($admin->id, $refund->actor_id);
        $this->assertSame('credit', $refund->type);
        $this->assertSame('refunded', $record->fresh()->status);
        $form->call('refund', $record->id)->assertHasNoErrors();
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(1, AdminAudit::query()->where('event', 'service_request.refunded')->count());
        $this->actingAs($record->user);
        $this->get(route('transactions'))->assertOk()->assertSee($refund->reference);
        Livewire::test(NinValidation::class)->assertSee('Refunded')->assertSee('Your payment has been refunded to your wallet.');
    }

    #[TestWith(['users', []])]
    #[TestWith(['agent', []])]
    #[TestWith(['staff', ['requests.view']])]
    #[TestWith(['staff', ['requests.manage']])]
    public function test_refunds_are_denied_without_explicit_permission(string $role, array $permissions): void
    {
        $record = $this->paidRequest();
        $actor = $role === 'staff' ? User::factory()->staff($permissions)->create() : User::factory()->create(['role' => $role]);
        $this->actingAs($actor);
        if ($role === 'staff') {
            Livewire::test(Requests::class)->assertDontSee('wire:click="refund(', false)->call('refund', $record->id)->assertForbidden();
        } else {
            try {
                app(RefundServiceRequest::class)->refund($actor, $record->id);
                $this->fail('Unauthorized refund succeeded.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame(37500, $record->user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_staff_can_refund_only_after_receiving_the_refund_permission(): void
    {
        $record = $this->paidRequest();
        $staff = User::factory()->staff(['requests.refund'])->create();
        $this->assertContains('requests.view', $staff->staff_permissions);
        $this->actingAs($staff);
        Livewire::test(Requests::class, ['requestId' => $record->id])->call('refund', $record->id)->assertHasNoErrors()->assertSet('status', 'refunded');
        $this->assertSame(50000, $record->user->fresh()->wallet_balance);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_free_and_unpaid_requests_cannot_create_a_refund(bool $free): void
    {
        $record = $free ? $this->paidRequest(0) : ServiceRequest::factory()->create();
        $balance = $record->user->wallet_balance;
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Requests::class)->call('refund', $record->id)->assertHasErrors('refund');
        $this->assertSame($balance, $record->user->fresh()->wallet_balance);
        $this->assertSame(0, WalletTransaction::query()->whereNotNull('refund_of_id')->count());
        $this->assertSame('pending', $record->fresh()->status);
    }

    public function test_refund_rejects_balance_overflow_without_changing_any_record(): void
    {
        $record = $this->paidRequest();
        $record->user->wallet_balance = Money::MAX_MINOR;
        $record->user->save();
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Requests::class)->call('refund', $record->id)->assertHasErrors('refund');
        $this->assertSame(Money::MAX_MINOR, $record->user->fresh()->wallet_balance);
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_failed_audit_rolls_back_the_refund_and_status_change(): void
    {
        $record = $this->paidRequest();
        $admin = User::factory()->administrator()->create();
        AdminAudit::creating(function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            app(RefundServiceRequest::class)->refund($admin, $record->id);
            $this->fail('Refund succeeded without audit.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            AdminAudit::flushEventListeners();
        }
        $this->assertSame(37500, $record->user->fresh()->wallet_balance);
        $this->assertSame('pending', $record->fresh()->status);
        $this->assertSame(0, $record->fresh()->revision);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_status_editing_cannot_issue_or_undo_a_refund(): void
    {
        $record = $this->paidRequest();
        $this->actingAs(User::factory()->administrator()->create());
        $form = Livewire::test(Requests::class, ['requestId' => $record->id]);
        $form->set('status', 'refunded')->call('save')->assertHasErrors('status');
        $this->assertSame(37500, $record->user->fresh()->wallet_balance);
        $form->call('refund', $record->id)->assertHasNoErrors()->assertSet('status', 'refunded');
        $form->set('status', 'completed')->set('reply', 'Incorrect status')->call('save')->assertHasErrors('status');
        $this->assertSame('refunded', $record->fresh()->status);
        $form->set('status', 'refunded')->set('reply', 'Refund processed.')->call('save')->assertHasNoErrors();
        $this->assertSame('Refund processed.', $record->fresh()->reply);
    }

    public function test_refund_permission_revocation_blocks_an_open_admin_page(): void
    {
        $record = $this->paidRequest();
        $staff = User::factory()->staff(['requests.refund'])->create();
        $this->actingAs($staff);
        $form = Livewire::test(Requests::class);
        $staff->staff_permissions = ['requests.view'];
        $staff->save();
        $form->call('refund', $record->id)->assertForbidden();
        $this->assertSame(37500, $record->user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_admin_table_escapes_submitted_inputs_and_replies(): void
    {
        $record = ServiceRequest::factory()->create(['service' => 'bvn-retrieval', 'method' => 'phone', 'payload' => ['phone' => '08012345678', 'full_name' => '<img src=x onerror=alert(1)>'], 'reply' => '<script>alert(1)</script>']);
        $this->actingAs(User::factory()->administrator()->create());
        $this->get(route('admin.requests'))->assertOk()
            ->assertSee('<img src=x onerror=alert(1)>')->assertDontSee('<img src=x onerror=alert(1)>', false)
            ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false);
    }

    private function paidRequest(int $price = 12500): ServiceRequest
    {
        ServicePrice::factory()->create(['service' => 'validations', 'method' => 'nin', 'amount' => $price]);

        return app(SubmitServiceRequest::class)->create(User::factory()->create(['wallet_balance' => 50000]), 'validations', 'nin', ['nin' => '01234567890'], (string) Str::uuid());
    }
}
