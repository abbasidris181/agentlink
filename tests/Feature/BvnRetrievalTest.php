<?php

namespace Tests\Feature;

use App\Livewire\BvnRetrieval;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BvnRetrievalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_cannot_access_bvn_retrieval(): void
    {
        $this->get(route('bvn.retrieval'))->assertRedirect(route('home'));
        Livewire::test(BvnRetrieval::class)->assertForbidden();
    }

    public function test_signed_in_users_see_phone_and_crm_options_and_retrieval_history(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('bvn.retrieval'))->assertOk()->assertSeeLivewire(BvnRetrieval::class)
            ->assertSee('BVN retrieval')->assertSee('Phone')->assertSee('CRM')
            ->assertSee('Registered phone number')->assertSee('Full name (as on record)')
            ->assertSeeInOrder(['Retrieval history', 'Transaction id', 'Date', 'Input field', 'Reply', 'Price', 'Status', 'No requests yet'])
            ->assertDontSee('Welcome back,')->assertDontSee('Upload screenshot proof');
    }

    #[TestWith(['08012345678', '08012345678'])]
    #[TestWith(['+234 801 234 5678', '+2348012345678'])]
    public function test_phone_requests_are_collected_without_sending_an_api_request(string $phone, string $normalized): void
    {
        ServicePrice::factory()->create(['service' => 'bvn-retrieval', 'method' => 'phone', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();

        Livewire::test(BvnRetrieval::class)->set('phone', $phone)->set('fullName', '  Taylor Example  ')
            ->call('submit')->assertHasNoErrors()->assertSet('phone', $normalized)
            ->assertSet('fullName', 'Taylor Example')->assertSet('showAvailability', true)
            ->assertSee('Request submitted for admin review.')->assertSee('Pending');

        Http::assertNothingSent();
    }

    public function test_phone_requests_require_both_phone_and_full_name(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(BvnRetrieval::class)->call('submit')
            ->assertHasErrors(['phone' => 'required', 'fullName' => 'required'])
            ->assertHasNoErrors(['agentCode', 'ticketId', 'bmsTicket', 'screenshotProof'])
            ->assertSet('showAvailability', false);
    }

    #[TestWith(['12345'])]
    #[TestWith(['0801234567a'])]
    #[TestWith(['+23408012345678'])]
    public function test_invalid_registered_phone_numbers_are_rejected(string $phone): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(BvnRetrieval::class)->set('phone', $phone)->set('fullName', 'Taylor Example')
            ->call('submit')->assertHasErrors(['phone' => 'regex'])->assertSet('showAvailability', false)
            ->assertSee('Enter a valid registered phone number, with or without the +234 prefix.');
    }

    #[TestWith(['   ', 'required'])]
    #[TestWith(['long', 'max'])]
    public function test_invalid_full_names_are_rejected(string $name, string $rule): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(BvnRetrieval::class)->set('phone', '08012345678')
            ->set('fullName', $name === 'long' ? str_repeat('a', 201) : $name)
            ->call('submit')->assertHasErrors(['fullName' => $rule])->assertSet('showAvailability', false);
    }

    public function test_crm_requires_all_four_fields_and_displays_the_upload(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(BvnRetrieval::class)->set('method', 'crm')
            ->assertSee('Agent Code')->assertSee('Ticket ID')->assertSee('BMS Ticket')
            ->assertSee('Upload screenshot proof')->assertDontSee('Registered phone number')
            ->call('submit')->assertHasErrors([
                'agentCode' => 'required', 'ticketId' => 'required',
                'bmsTicket' => 'required', 'screenshotProof' => 'required',
            ])->assertHasNoErrors(['phone', 'fullName'])->assertSet('showAvailability', false)
            ->assertSee('Upload screenshot proof for your CRM request.');
    }

    #[TestWith(['png'])]
    #[TestWith(['jpg'])]
    #[TestWith(['webp'])]
    public function test_crm_collects_screenshot_proof_without_sending_an_api_request(string $extension): void
    {
        ServicePrice::factory()->create(['service' => 'bvn-retrieval', 'method' => 'crm', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        Storage::fake('local');
        Http::preventStrayRequests();
        $proof = UploadedFile::fake()->image('proof.'.$extension);

        Livewire::test(BvnRetrieval::class)->set('method', 'crm')
            ->set('agentCode', ' AG001 ')->set('ticketId', ' TKT001 ')->set('bmsTicket', ' BMS001 ')
            ->set('screenshotProof', $proof)->call('submit')->assertHasNoErrors()
            ->assertSet('agentCode', 'AG001')->assertSet('ticketId', 'TKT001')->assertSet('bmsTicket', 'BMS001')
            ->assertSet('showAvailability', true)->assertSee('Selected: proof.'.$extension)
            ->assertSee('Request submitted for admin review.')->assertSee('Pending');

        Http::assertNothingSent();
    }

    #[TestWith(['agentCode'])]
    #[TestWith(['ticketId'])]
    #[TestWith(['bmsTicket'])]
    public function test_crm_identifiers_have_a_length_limit(string $field): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(BvnRetrieval::class)->set('method', 'crm')
            ->set($field, str_repeat('a', 101))->call('submit')->assertHasErrors([$field => 'max'])
            ->assertSet('showAvailability', false);
    }

    public function test_crm_rejects_non_image_proof(): void
    {
        $this->actingAs(User::factory()->create());
        Storage::fake('local');

        Livewire::test(BvnRetrieval::class)->set('method', 'crm')
            ->set('screenshotProof', UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'))
            ->call('submit')->assertHasErrors(['screenshotProof' => 'image'])
            ->assertSet('showAvailability', false)->assertSee('Upload a valid screenshot image.');
    }

    public function test_crm_rejects_unsupported_image_formats(): void
    {
        $this->actingAs(User::factory()->create());
        Storage::fake('local');

        Livewire::test(BvnRetrieval::class)->set('method', 'crm')
            ->set('screenshotProof', UploadedFile::fake()->image('proof.gif'))
            ->call('submit')->assertHasErrors(['screenshotProof' => 'mimes'])
            ->assertSet('showAvailability', false)->assertSee('Upload a JPG, PNG, or WebP screenshot.');
    }

    public function test_crm_rejects_screenshots_larger_than_five_megabytes(): void
    {
        $this->actingAs(User::factory()->create());
        Storage::fake('local');

        Livewire::test(BvnRetrieval::class)->set('method', 'crm')
            ->set('screenshotProof', UploadedFile::fake()->image('proof.png')->size(5121))
            ->call('submit')->assertHasErrors(['screenshotProof' => 'max'])
            ->assertSet('showAvailability', false)->assertSee('The screenshot must be 5 MB or smaller.');
    }

    public function test_switching_options_clears_values_uploads_errors_and_availability(): void
    {
        ServicePrice::factory()->create(['service' => 'bvn-retrieval', 'method' => 'phone', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        Storage::fake('local');

        Livewire::test(BvnRetrieval::class)->set('phone', '08012345678')->set('fullName', 'Taylor Example')
            ->call('submit')->assertSet('showAvailability', true)
            ->set('method', 'crm')->assertSet('phone', '')->assertSet('fullName', '')
            ->assertSet('showAvailability', false)->call('submit')->assertHasErrors(['screenshotProof'])
            ->set('agentCode', 'AG001')->set('ticketId', 'TKT001')->set('bmsTicket', 'BMS001')
            ->set('screenshotProof', UploadedFile::fake()->image('proof.png'))
            ->set('method', 'phone')->assertHasNoErrors()->assertSet('screenshotProof', null)
            ->assertSet('agentCode', '')->assertSet('ticketId', '')->assertSet('bmsTicket', '');
    }

    public function test_unknown_retrieval_options_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(BvnRetrieval::class)->set('method', 'invalid')->call('submit')
            ->assertHasErrors(['method' => 'in'])->assertSet('showAvailability', false);
    }

    public function test_submission_is_forbidden_after_sign_out(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(BvnRetrieval::class);
        Auth::logout();

        $component->call('submit')->assertForbidden();
    }
}
