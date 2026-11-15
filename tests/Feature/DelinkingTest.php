<?php

namespace Tests\Feature;

use App\Livewire\Delinking;
use App\Models\ServicePrice;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DelinkingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_cannot_access_delinking(): void
    {
        $this->get(route('delinking'))->assertRedirect(route('home'));
        Livewire::test(Delinking::class)->assertForbidden();
    }

    public function test_signed_in_users_see_the_nin_input_and_delinking_history(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('delinking'))->assertOk()->assertSeeLivewire(Delinking::class)
            ->assertSee('NIN number')->assertSee('Enter your 11-digit NIN')->assertSee('Submit request')
            ->assertSeeInOrder(['Self-Service history', 'Transaction id', 'Input', 'Status', 'Date', 'Price', 'Reply', 'No Self-Service requests yet'])
            ->assertDontSee('Welcome back,')->assertDontSee('Verification method');
    }

    #[TestWith(['01234567890', '01234567890'])]
    #[TestWith(['12345678901', '12345678901'])]
    #[TestWith([' 01234567890 ', '01234567890'])]
    public function test_valid_nin_preserves_digits_without_sending_an_api_request(string $nin, string $normalized): void
    {
        ServicePrice::factory()->create(['service' => 'delinking', 'method' => 'nin', 'amount' => 0]);
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();

        Livewire::test(Delinking::class)->set('nin', $nin)->call('submit')
            ->assertHasNoErrors()->assertSet('nin', $normalized)->assertSet('showAvailability', true)
            ->assertSee('Request submitted for admin review.')->assertSee('Pending');

        Http::assertNothingSent();
    }

    #[TestWith(['', 'required'])]
    #[TestWith(['   ', 'required'])]
    #[TestWith(['1234567890', 'regex'])]
    #[TestWith(['123456789012', 'regex'])]
    #[TestWith(['1234567890a', 'regex'])]
    #[TestWith(['01234 567890', 'regex'])]
    public function test_invalid_nin_is_rejected(string $nin, string $rule): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Delinking::class)->set('nin', $nin)->call('submit')
            ->assertHasErrors(['nin' => $rule])->assertSet('showAvailability', false)
            ->assertSee($rule === 'required' ? 'Enter your 11-digit NIN number.' : 'Enter an 11-digit NIN number.');
    }

    public function test_invalid_resubmission_clears_the_availability_message(): void
    {
        ServicePrice::factory()->create(['service' => 'delinking', 'method' => 'nin', 'amount' => 0]);
        $this->actingAs(User::factory()->create());

        Livewire::test(Delinking::class)->set('nin', '01234567890')->call('submit')
            ->assertSet('showAvailability', true)
            ->set('nin', 'invalid')->call('submit')->assertHasErrors(['nin'])
            ->assertSet('showAvailability', false)->assertDontSee('Request submitted for admin review.');
    }

    #[TestWith([['delinking'], '', 'Delinking'])]
    #[TestWith([['delinking'], 'person@example.test', 'Delinking'])]
    #[TestWith([['email_retrieve'], '', 'Email retrive'])]
    #[TestWith([['email_retrieve', 'delinking'], 'person@example.test', 'Delinking, Email retrive'])]
    public function test_self_service_types_and_optional_email_are_saved_with_one_charge(array $types, string $email, string $expectedTypes): void
    {
        ServicePrice::factory()->create(['service' => 'delinking', 'method' => 'email_retrieve', 'amount' => 2500]);
        $user = User::factory()->create(['wallet_balance' => 50000]);
        ServicePrice::factory()->create(['service' => 'delinking', 'method' => 'nin', 'amount' => 12500]);
        $this->actingAs($user);
        Http::preventStrayRequests();

        $component = Livewire::test(Delinking::class)->set('types', $types)->set('nin', '01234567890')->set('email', $email);
        $this->assertSame(1, substr_count($component->html(), 'id="verification-nin"'));
        $component->call('submit')->assertHasNoErrors()->assertSee('Request submitted for admin review.')->assertSee($expectedTypes);
        $record = ServiceRequest::query()->sole();
        $this->assertSame($expectedTypes, $record->payload['types']);
        $this->assertSame($email === '' ? null : $email, $record->payload['email'] ?? null);
        $this->assertSame(match ($expectedTypes) {
            'Delinking' => 37500, 'Email retrive' => 47500, default => 35000,
        }, $user->fresh()->wallet_balance);
        $this->assertSame('Self-Service', $record->serviceLabel());
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertStringContainsString('01234567890', $record->getRawOriginal('payload'));
        $this->assertSame($expectedTypes, $record->inputDetails()['Type']);
        $this->actingAs(User::factory()->administrator()->create());
        $this->get(route('admin.requests.show', ['requestId' => $record->id]))->assertOk()->assertSee('Self-Service')->assertSee($expectedTypes);
        if ($email !== '') {
            $this->get(route('admin.requests.service', ['service' => 'delinking']))->assertOk()->assertSee($email);
        }
        Http::assertNothingSent();
    }

    #[TestWith([[], '', 'types'])]
    #[TestWith([['unknown'], '', 'types.0'])]
    #[TestWith([['delinking', 'delinking'], '', 'types.0'])]
    #[TestWith([['delinking'], 'invalid', 'email'])]
    public function test_invalid_types_or_optional_email_are_rejected(array $types, string $email, string $error): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(Delinking::class)->set('types', $types)->set('nin', '01234567890')->set('email', $email)
            ->call('submit')->assertHasErrors([$error])->assertSet('showAvailability', false);
        $this->assertDatabaseCount('service_requests', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_email_input_only_appears_for_delinking_and_is_cleared_when_deselected(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(Delinking::class)->assertSee('Email (optional)')
            ->set('nin', '01234567890')->set('email', 'person@example.test')
            ->set('types', ['delinking', 'email_retrieve'])->assertSet('email', 'person@example.test')
            ->set('types', ['email_retrieve'])->assertSet('email', '')->assertSet('nin', '01234567890')
            ->assertDontSee('id="self-service-email"', false)
            ->set('types', ['delinking'])->assertSee('Email (optional)');
    }

    public function test_submission_is_forbidden_after_sign_out(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(Delinking::class);
        Auth::logout();

        $component->call('submit')->assertForbidden();
    }
}
