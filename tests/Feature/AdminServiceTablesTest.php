<?php

namespace Tests\Feature;

use App\Livewire\Admin\Requests;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\Models\User;
use App\ServiceCatalog;
use App\SubmitServiceRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminServiceTablesTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{string, string, array<string, string>, list<string>}> */
    public static function services(): array
    {
        return [
            'validations' => ['validations', 'nin', ['nin' => '01234567890'], ['NIN number']],
            'delinking' => ['delinking', 'nin', ['nin' => '12345678901'], ['NIN number']],
            'ipe clearing' => ['ipe-clearing', 'tracking', ['tracking_id' => 'ABC123DEF456GH7'], ['Tracking ID']],
            'mod ipe' => ['mod-ipe', 'tracking', ['tracking_id' => 'XYZ123DEF456GH7'], ['Tracking ID']],
            'bvn retrieval' => ['bvn-retrieval', 'crm', ['agent_code' => 'AG001', 'ticket_id' => 'TK001', 'bms_ticket' => 'BMS001'], ['Registered phone number', 'Full name (as on record)', 'Agent code', 'Ticket ID', 'BMS ticket', 'Screenshot proof']],
        ];
    }

    #[DataProvider('services')]
    public function test_each_service_has_its_own_table_with_input_headings(string $service, string $method, array $input, array $headings): void
    {
        $record = ServiceRequest::factory()->create(['service' => $service, 'method' => $method, 'payload' => $input]);
        $other = ServiceRequest::factory()->create(['service' => $service === 'validations' ? 'delinking' : 'validations']);
        $this->actingAs(User::factory()->administrator()->create());
        $page = $this->get(route('admin.requests.service', ['service' => $service]))->assertOk()
            ->assertSee(ServiceCatalog::all()[$service]['label'].' requests')
            ->assertSee($record->reference)->assertDontSee($other->reference)
            ->assertDontSee('>Submitted inputs</th>', false)->assertSee('>Reply</th>', false)->assertSee('>Actions</th>', false)
            ->assertSee('Save reply')->assertSee('No refundable payment');
        foreach ($headings as $heading) {
            $page->assertSee('>'.$heading.'</span>', false);
        }
        foreach ($input as $value) {
            $page->assertSee($value);
        }
        $page->assertDontSee('id="request-service"', false);
    }

    public function test_nin_verifications_are_excluded_from_request_navigation_filters_and_tables(): void
    {
        $records = [];
        foreach (['nin-v1', 'nin-v2', 'nin-v3'] as $service) {
            $records[$service] = ServiceRequest::factory()->create([
                'service' => $service, 'method' => 'demographics',
                'payload' => ['first_name' => 'Taylor', 'surname' => 'Example', 'dob' => '1990-01-01', 'gender' => 'female'],
            ]);
        }
        $manualRequest = ServiceRequest::factory()->create(['service' => 'nin-modification', 'method' => 'first', 'payload' => ['nin' => '01234567890', 'types' => 'gender', 'gender' => 'female']]);
        $this->actingAs(User::factory()->administrator()->create());
        $page = $this->get(route('admin.requests'))->assertOk()->assertSee($manualRequest->reference);
        foreach ($records as $service => $record) {
            $page->assertDontSee($record->reference)
                ->assertDontSee('href="'.route('admin.requests.service', ['service' => $service]).'"', false)
                ->assertDontSee('value="'.$service.'"', false);
            $this->get(route('admin.requests.service', ['service' => $service]))->assertNotFound();
            Livewire::test(Requests::class, ['service' => $service])->assertNotFound();
            $this->assertDatabaseHas('service_requests', ['id' => $record->id]);
        }
        Livewire::test(Requests::class)->set('serviceFilter', 'nin-v1')->assertSee($manualRequest->reference)
            ->assertDontSee($records['nin-v1']->reference);
    }

    public function test_request_tables_offer_one_checkbox_per_row_to_copy_all_inputs(): void
    {
        $record = ServiceRequest::factory()->create([
            'service' => 'bvn-retrieval', 'method' => 'crm',
            'payload' => ['agent_code' => 'AG001', 'ticket_id' => 'TK001', 'bms_ticket' => 'BMS001', 'proof_sha256' => 'private-hash'],
            'screenshot_path' => 'private/proof.png',
        ]);
        $this->actingAs(User::factory()->staff(['requests.view'])->create());
        $page = $this->get(route('admin.requests.service', ['service' => 'bvn-retrieval']))->assertOk()
            ->assertSee('Copy user inputs')->assertSee('Select all rows')->assertSee('Copy selected rows')
            ->assertSee('aria-label="Select request '.$record->reference.' to copy all inputs"', false)
            ->assertSee('data-copy-label="Agent code" data-copy-value="AG001"', false)
            ->assertSee('data-copy-label="Ticket ID" data-copy-value="TK001"', false)
            ->assertSee('data-copy-label="BMS ticket" data-copy-value="BMS001"', false)
            ->assertSee('data-copy-label="Screenshot proof" data-copy-value="'.route('service-requests.proof', $record).'"', false)
            ->assertSee('x-on:change="$nextTick(() => copySelected())"', false)
            ->assertDontSee('data-copy-column', false)
            ->assertDontSee('data-copy-field="proof_sha256"', false)
            ->assertDontSee('data-copy-field="reply"', false)
            ->assertDontSee('data-copy-field="email"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'data-select-row'));
        $second = ServiceRequest::factory()->create(['service' => 'bvn-retrieval', 'method' => 'phone', 'payload' => ['phone' => '08165072514', 'full_name' => 'Taylor Example']]);
        $page = $this->get(route('admin.requests'))->assertOk()->assertSee($record->reference)->assertSee($second->reference)
            ->assertSee('data-copy-label="Ticket ID" data-copy-value="TK001"', false)
            ->assertSee('data-copy-label="Registered phone number" data-copy-value="08165072514"', false)
            ->assertSee('data-copy-label="Full name (as on record)" data-copy-value="Taylor Example"', false)
            ->assertDontSee('private-hash')->assertDontSee('data-copy-column', false);
        $this->assertSame(2, substr_count($page->getContent(), 'data-select-row'));
    }

    public function test_input_copy_attributes_escape_user_supplied_markup(): void
    {
        $complaint = 'Help "><img src=x onerror=alert(1)>';
        ServiceRequest::factory()->create(['service' => 'complaints', 'method' => 'complaint', 'payload' => ['types' => 'Other', 'complaint' => $complaint]]);
        $this->actingAs(User::factory()->administrator()->create());
        $this->get(route('admin.requests.service', ['service' => 'complaints']))->assertOk()
            ->assertSee('data-copy-value="'.e($complaint).'"', false)
            ->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_sidebar_lists_each_service_under_an_accessible_dropdown(): void
    {
        $this->actingAs(User::factory()->staff(['requests.view'])->create());
        $page = $this->get(route('admin.requests.service', ['service' => 'validations']))->assertOk()
            ->assertSee('<details class="group"', false)->assertSee('<summary', false);
        foreach (ServiceRequest::ADMIN_SERVICES as $service) {
            $page->assertSee(route('admin.requests.service', ['service' => $service]), false);
        }
        $this->actingAs(User::factory()->staff()->create());
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('>Service requests</span>', false);
    }

    public function test_service_table_requires_authentication_and_request_permission(): void
    {
        $url = route('admin.requests.service', ['service' => 'validations']);
        $this->get($url)->assertRedirect(route('home'));
        $this->actingAs(User::factory()->staff()->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
        $this->actingAs(User::factory()->administrator()->create());
        $this->get(route('admin.requests.service', ['service' => 'nin-v1']))->assertNotFound();
        Livewire::test(Requests::class, ['service' => 'unknown'])->assertNotFound();
    }

    public function test_changing_filters_cannot_mix_other_services_into_a_dedicated_table(): void
    {
        $record = ServiceRequest::factory()->create(['service' => 'validations', 'status' => 'pending']);
        $other = ServiceRequest::factory()->create(['service' => 'delinking']);
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Requests::class, ['service' => 'validations'])->assertSee($record->reference)->assertDontSee($other->reference)
            ->set('serviceFilter', 'delinking')->assertSee($record->reference)->assertDontSee($other->reference)
            ->set('statusFilter', 'completed')->assertDontSee($record->reference)
            ->set('statusFilter', 'all')->set('search', $other->user->email)->assertDontSee($other->reference);
    }

    public function test_bvn_phone_and_crm_inputs_occupy_their_own_columns_in_one_service_table(): void
    {
        $phone = ServiceRequest::factory()->create(['service' => 'bvn-retrieval', 'method' => 'phone', 'payload' => ['phone' => '08012345678', 'full_name' => 'Taylor Example']]);
        $crm = ServiceRequest::factory()->create(['service' => 'bvn-retrieval', 'method' => 'crm', 'payload' => ['agent_code' => 'AG001', 'ticket_id' => 'TK001', 'bms_ticket' => 'BMS001'], 'screenshot_path' => 'private/proof.png']);
        $this->actingAs(User::factory()->administrator()->create());
        $this->get(route('admin.requests.service', ['service' => 'bvn-retrieval']))->assertOk()
            ->assertSee($phone->reference)->assertSee($crm->reference)->assertSee('08012345678')->assertSee('Taylor Example')
            ->assertSee('AG001')->assertSee('TK001')->assertSee('BMS001')->assertSee(route('service-requests.proof', $crm), false);
    }

    public function test_reply_and_refund_work_from_a_dedicated_service_table(): void
    {
        ServicePrice::factory()->create(['service' => 'validations', 'method' => 'nin', 'amount' => 12500]);
        $owner = User::factory()->create(['wallet_balance' => 50000]);
        $record = app(SubmitServiceRequest::class)->create($owner, 'validations', 'nin', ['nin' => '01234567890'], (string) Str::uuid());
        $this->actingAs(User::factory()->administrator()->create());
        Livewire::test(Requests::class, ['service' => 'validations'])->assertSee('Refund 125.00')
            ->set('replies.'.$record->id, 'We are reviewing your NIN.')->call('saveReply', $record->id, 0)->assertHasNoErrors()
            ->call('refund', $record->id)->assertHasNoErrors()->assertSee('Refunded 125.00');
        $this->assertSame('We are reviewing your NIN.', $record->fresh()->reply);
        $this->assertSame('refunded', $record->fresh()->status);
        $this->assertSame(50000, $owner->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->get(route('admin.requests.show', ['requestId' => $record->id]))->assertOk()
            ->assertSee(route('admin.requests.service', ['service' => 'validations']), false);
    }
}
