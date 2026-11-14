<?php

namespace Tests\Feature;

use App\Livewire\Admin\Requests;
use App\Livewire\Complaints;
use App\Models\ServiceRequest;
use App\Models\User;
use App\ServiceCatalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ComplaintsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_complaints_is_in_the_user_sidebar_and_requires_sign_in(): void
    {
        $this->get(route('complaints'))->assertRedirect(route('home'));
        Livewire::test(Complaints::class)->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk()->assertSee('href="'.route('complaints').'"', false);
        $this->get(route('complaints'))->assertOk()->assertSeeLivewire(Complaints::class)
            ->assertSee('Complains')->assertSee('Complain')->assertSee('Other')->assertSee('Submit complaint')
            ->assertDontSee('Explore services');
        $component = Livewire::test(Complaints::class);
        $this->assertSame(16, substr_count($component->html(), 'type="checkbox"'));
        foreach (ServiceCatalog::dashboardServices() as $service) {
            $component->assertSee($service['name']);
        }
    }

    #[TestWith([['nin-v1']])]
    #[TestWith([['personalization', 'nin-modification', 'bvn-modification']])]
    #[TestWith([['bvn retrival', 'other']])]
    public function test_complaints_are_saved_privately_without_wallet_charges_and_duplicate_submissions(array $types): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        $this->actingAs($user);
        $component = Livewire::test(Complaints::class)->set('types', $types)->set('complaint', '  Please help with my service request.  ')
            ->call('submit')->assertHasNoErrors()->assertSee('Complaint submitted successfully.')->assertSee('Pending');
        $record = ServiceRequest::query()->sole();
        $this->assertSame('complaints', $record->service);
        $this->assertSame('complaint', $record->method);
        $this->assertSame('Please help with my service request.', $record->payload['complaint']);
        $this->assertSame($user->id, $record->user_id);
        $this->assertSame(0, $record->quoted_price);
        $this->assertStringNotContainsString('Please help', $record->getRawOriginal('payload'));
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        $component->call('submit')->assertHasNoErrors()->assertSet('submittedReference', $record->reference);
        $this->assertDatabaseCount('service_requests', 1);
        $this->assertDatabaseCount('admin_audits', 1);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    #[TestWith([[], 'Message', 'types'])]
    #[TestWith([['unknown'], 'Message', 'types.0'])]
    #[TestWith([['other', 'other'], 'Message', 'types.0'])]
    #[TestWith([['other'], '', 'complaint'])]
    #[TestWith([['other'], '   ', 'complaint'])]
    public function test_a_valid_type_and_nonempty_complaint_are_required(array $types, string $message, string $error): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(Complaints::class)->set('types', $types)->set('complaint', $message)->call('submit')->assertHasErrors([$error]);
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_overlong_complaints_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(Complaints::class)->set('types', ['other'])->set('complaint', str_repeat('a', 5001))
            ->call('submit')->assertHasErrors(['complaint' => 'max']);
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_complaints_are_visible_to_admins_and_replies_appear_in_the_owners_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test(Complaints::class)->set('types', ['nin-v1', 'other'])->set('complaint', 'My request needs attention.')
            ->call('submit')->assertHasNoErrors();
        $record = ServiceRequest::query()->sole();
        $this->actingAs(User::factory()->administrator()->create());
        $this->get(route('admin.requests.service', ['service' => 'complaints']))->assertOk()
            ->assertSee('Complains requests')->assertSee('My request needs attention.')->assertSee('NIN-V1, Other');
        Livewire::test(Requests::class, ['requestId' => $record->id])->set('status', 'completed')
            ->set('reply', 'We have resolved your request.')->call('save')->assertHasNoErrors();
        $this->actingAs($user);
        Livewire::test(Complaints::class)->assertSee('Completed')->assertSee('We have resolved your request.');
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_other_users_cannot_view_complaints_or_the_admin_review_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test(Complaints::class)->set('types', ['other'])->set('complaint', 'Private complaint text.')->call('submit');
        $record = ServiceRequest::query()->sole();
        $this->actingAs(User::factory()->create());
        Livewire::test(Complaints::class)->assertDontSee('Private complaint text.')->assertDontSee($record->reference);
        $this->get(route('admin.requests.show', ['requestId' => $record->id]))->assertForbidden();
    }

    public function test_complaint_text_and_admin_replies_are_escaped(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Livewire::test(Complaints::class)->set('types', ['other'])->set('complaint', '<script>alert(1)</script>')->call('submit')
            ->assertHasNoErrors()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
        ServiceRequest::query()->sole()->update(['reply' => '<img src=x onerror=alert(1)>']);
        Livewire::test(Complaints::class)->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_updating_the_form_allows_a_new_complaint(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(Complaints::class)->set('types', ['other'])->set('complaint', 'First complaint.')->call('submit');
        $component->set('complaint', 'Second complaint.')->assertSet('submittedReference', '')->call('submit')->assertHasNoErrors();
        $this->assertDatabaseCount('service_requests', 2);
    }

    public function test_signed_out_users_cannot_submit_complaints(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(Complaints::class)->set('types', ['other'])->set('complaint', 'Help me.');
        Auth::logout();
        $component->call('submit')->assertForbidden();
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_inactive_users_cannot_submit_complaints(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $component = Livewire::test(Complaints::class)->set('types', ['other'])->set('complaint', 'Help me.');
        $user->forceFill(['is_active' => false])->save();
        $component->call('submit')->assertForbidden();
        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_complaints_do_not_add_a_billable_price_entry(): void
    {
        $this->assertArrayNotHasKey('complaints', ServiceCatalog::all());
        $this->assertArrayHasKey('complaints', ServiceCatalog::requestServices());
    }
}
