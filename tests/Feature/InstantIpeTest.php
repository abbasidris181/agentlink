<?php

namespace Tests\Feature;

use App\Jobs\SubmitInstantIpeRequest;
use App\Livewire\InstantIpe;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class InstantIpeTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_cannot_access_instant_ipe(): void
    {
        $this->get(route('ipe.instant'))->assertRedirect(route('home'));
        Livewire::test(InstantIpe::class)->assertForbidden();
    }

    public function test_signed_in_users_see_single_bulk_options_and_history_columns(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('ipe.instant'))->assertOk()->assertSeeLivewire(InstantIpe::class)
            ->assertSee('Single tracking ID')->assertSee('Bulk input')
            ->assertSeeInOrder(['Instant IPE history', 'Transaction ID', 'Tracking ID', 'Batch ID', 'Action', 'Reply', 'Price', 'Status', 'Date'])
            ->assertSee('No Instant IPE transactions yet')->assertDontSee('Welcome back,');
    }

    #[TestWith(['single'])]
    #[TestWith(['bulk'])]
    public function test_one_id_is_uppercase_and_has_no_batch_reference(string $mode): void
    {
        $this->configureProcessing($mode);
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();

        Livewire::test(InstantIpe::class)->set('mode', $mode)->set('trackingInput', ' 000abc123def456 ')
            ->call('submit')->assertHasNoErrors()->assertSet('trackingInput', '')
            ->assertSet('trackingIds', ['000ABC123DEF456'])->assertSet('batchId', null)
            ->assertSet('showAvailability', true)->assertSee('1 tracking ID submitted.')
            ->assertDontSee('Batch ID:')->assertSee('Requests are submitted for processing.');

        Http::assertNothingSent();
    }

    #[TestWith(['abc123def456gh7\nxyz987uvw654st3'])]
    #[TestWith(['abc123def456gh7, xyz987uvw654st3'])]
    #[TestWith(['abc123def456gh7;xyz987uvw654st3'])]
    #[TestWith(['abc123def456gh7 xyz987uvw654st3'])]
    #[TestWith(['abc123def456gh7xyz987uvw654st3'])]
    public function test_bulk_ids_are_normalized_and_share_an_eight_digit_batch_reference(string $input): void
    {
        $this->configureProcessing('bulk');
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
        $input = str_replace('\\n', "\n", $input);

        $component = Livewire::test(InstantIpe::class)->set('mode', 'bulk')->set('trackingInput', $input)
            ->call('submit')->assertHasNoErrors()
            ->assertSet('trackingInput', '')
            ->assertSet('trackingIds', ['ABC123DEF456GH7', 'XYZ987UVW654ST3'])
            ->assertSet('showAvailability', true)->assertSee('2 tracking IDs submitted.')
            ->assertSee('Batch ID:')->assertSee('Requests are submitted for processing.');

        $this->assertMatchesRegularExpression('/\A[1-9][0-9]{7}\z/', $component->get('batchId'));
        Http::assertNothingSent();
    }

    public function test_blank_lines_and_trailing_separators_do_not_create_additional_ids(): void
    {
        $this->configureProcessing('bulk');
        $this->actingAs(User::factory()->create());

        Livewire::test(InstantIpe::class)->set('mode', 'bulk')
            ->set('trackingInput', "\nABC123DEF456GH7\r\n\r\n, ; \n")
            ->call('submit')->assertHasNoErrors()->assertSet('trackingIds', ['ABC123DEF456GH7'])
            ->assertSet('trackingInput', '')->assertSet('batchId', null);
    }

    #[TestWith(['single', ''])]
    #[TestWith(['bulk', ', ; '])]
    public function test_empty_input_is_rejected(string $mode, string $input): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(InstantIpe::class)->set('mode', $mode)->set('trackingInput', $input)
            ->call('submit')->assertHasErrors(['trackingIds' => 'required'])
            ->assertSet('batchId', null)->assertSet('showAvailability', false);
    }

    #[TestWith(['ABC123DEF456GH'])]
    #[TestWith(['ABC123DEF456GH78'])]
    #[TestWith(['ABC123DEF456GH!'])]
    #[TestWith(['ABC123 DEF456GH'])]
    #[TestWith(['ABC123DEF456GH7,XYZ987UVW654ST3'])]
    public function test_single_mode_rejects_invalid_or_multiple_ids(string $input): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(InstantIpe::class)->set('trackingInput', $input)->call('submit')
            ->assertHasErrors(['trackingIds.0' => 'regex'])->assertSet('batchId', null)
            ->assertSet('showAvailability', false)->assertSet('trackingInput', $input)
            ->assertSee('Tracking ID on line 1 must contain exactly 15 letters or digits.');
    }

    public function test_invalid_bulk_line_rejects_the_whole_list_and_reports_its_position(): void
    {
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();

        Livewire::test(InstantIpe::class)->set('mode', 'bulk')
            ->set('trackingInput', "ABC123DEF456GH7\nINVALID\nXYZ987UVW654ST3")->call('submit')
            ->assertHasErrors(['trackingIds.1' => 'regex'])->assertSet('batchId', null)
            ->assertSet('showAvailability', false)
            ->assertSee('Tracking ID on line 2 must contain exactly 15 letters or digits.');

        Http::assertNothingSent();
    }

    public function test_bulk_input_does_not_have_a_small_fixed_count_limit(): void
    {
        $this->configureProcessing('bulk');
        $this->actingAs(User::factory()->create());
        $ids = array_map(fn (int $index): string => sprintf('ABC%012d', $index), range(1, 150));

        $component = Livewire::test(InstantIpe::class)->set('mode', 'bulk')
            ->set('trackingInput', implode("\n", $ids))->call('submit')->assertHasNoErrors()
            ->assertSet('trackingIds', $ids)->assertSee('150 tracking IDs submitted.');

        $this->assertMatchesRegularExpression('/\A[1-9][0-9]{7}\z/', $component->get('batchId'));
    }

    public function test_changing_modes_clears_previous_input_and_batch_details(): void
    {
        $this->configureProcessing('bulk');
        $this->actingAs(User::factory()->create());

        Livewire::test(InstantIpe::class)->set('mode', 'bulk')
            ->set('trackingInput', "ABC123DEF456GH7\nXYZ987UVW654ST3")->call('submit')
            ->assertSet('showAvailability', true)->set('mode', 'single')
            ->assertSet('trackingInput', '')->assertSet('trackingIds', [])->assertSet('batchId', null)
            ->assertSet('preparedInput', '')->assertSet('showAvailability', false)->assertHasNoErrors();
    }

    public function test_failed_resubmission_clears_the_previous_batch_reference(): void
    {
        $this->configureProcessing('bulk');
        $this->actingAs(User::factory()->create());

        Livewire::test(InstantIpe::class)->set('mode', 'bulk')
            ->set('trackingInput', "ABC123DEF456GH7\nXYZ987UVW654ST3")->call('submit')
            ->assertSet('showAvailability', true)->set('trackingInput', 'INVALID')->call('submit')
            ->assertHasErrors(['trackingIds.0'])->assertSet('batchId', null)
            ->assertSet('showAvailability', false)->assertDontSee('Batch ID:');
    }

    public function test_unknown_modes_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(InstantIpe::class)->set('mode', 'invalid')
            ->set('trackingInput', 'ABC123DEF456GH7')->call('submit')
            ->assertHasErrors(['mode' => 'in'])->assertSet('batchId', null)
            ->assertSet('showAvailability', false);
    }

    public function test_submission_is_forbidden_after_sign_out(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(InstantIpe::class);
        Auth::logout();

        $component->call('submit')->assertForbidden();
    }

    private function configureProcessing(string $mode): void
    {
        config(['services.tracking_id.tracking_url' => 'https://ipe.example.test', 'services.tracking_id.tracking_token' => 'test-token']);
        Queue::fake([SubmitInstantIpeRequest::class]);
        ServicePrice::factory()->create(['service' => 'instant-ipe', 'method' => $mode, 'amount' => 0]);
    }
}
