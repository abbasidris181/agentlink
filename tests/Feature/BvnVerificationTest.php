<?php

namespace Tests\Feature;

use App\Livewire\BvnVerification;
use App\Models\BvnVerificationRecord;
use App\Models\ServicePrice;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BvnVerificationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_cannot_access_bvn_verification(): void
    {
        $this->get(route('bvn.verify'))->assertRedirect(route('home'));
        Livewire::test(BvnVerification::class)->assertForbidden();
    }

    public function test_signed_in_users_see_the_bvn_form_and_empty_history(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('bvn.verify'))->assertOk()
            ->assertSeeLivewire(BvnVerification::class)
            ->assertSee('I consent to the use of the details provided for this BVN verification.')
            ->assertSee('type="checkbox"', false)
            ->assertSee('BVN verification')->assertSee('BVN number')
            ->assertSee('Enter your 11-digit BVN')
            ->assertSeeInOrder(['Verification history', 'Transaction id', 'Method', 'Status', 'Date', 'Price', 'No verifications yet'])
            ->assertSee('BVN verification history')
            ->assertDontSee('Verification method')->assertDontSee('Welcome back,');
    }

    #[TestWith(['01234567890', '01234567890'])]
    #[TestWith(['12345678901', '12345678901'])]
    #[TestWith([' 01234567890 ', '01234567890'])]
    public function test_valid_bvn_preserves_digits_and_creates_verified_history(string $bvn, string $normalized): void
    {
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
        $this->fakeProvider($normalized);

        Livewire::test(BvnVerification::class)->set('bvn', $bvn)->set('consent', true)->call('verify')
            ->assertHasNoErrors()->assertSet('bvn', $normalized)
            ->assertSet('showAvailability', false);

        $this->assertSame($normalized, BvnVerificationRecord::query()->sole()->bvn);
        Http::assertSentCount(1);
    }

    #[TestWith(['', 'required'])]
    #[TestWith(['   ', 'required'])]
    #[TestWith(['1234567890', 'regex'])]
    #[TestWith(['123456789012', 'regex'])]
    #[TestWith(['1234567890a', 'regex'])]
    #[TestWith(['01234 567890', 'regex'])]
    public function test_invalid_bvn_is_rejected(string $bvn, string $rule): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(BvnVerification::class)->set('bvn', $bvn)->set('consent', true)->call('verify')
            ->assertHasErrors(['bvn' => $rule])->assertSet('showAvailability', false)
            ->assertSee($rule === 'required' ? 'Enter your 11-digit BVN number.' : 'Enter an 11-digit BVN number.');
    }

    public function test_invalid_resubmission_clears_the_previous_availability_message(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeProvider('01234567890');

        Livewire::test(BvnVerification::class)->set('bvn', '01234567890')->set('consent', true)->call('verify')
            ->assertHasNoErrors()->assertSet('showAvailability', false)
            ->set('bvn', 'invalid')->set('consent', true)->call('verify')->assertHasErrors(['bvn'])
            ->assertSet('showAvailability', false)->assertDontSee('No verification has been performed.');
    }

    public function test_consent_is_required_for_bvn_verification(): void
    {
        $this->actingAs(User::factory()->create());
        $this->fakeProvider('01234567890');

        Livewire::test(BvnVerification::class)->set('bvn', '01234567890')
            ->assertSet('consent', false)->call('verify')
            ->assertHasErrors(['consent' => 'accepted'])->assertSet('showAvailability', false)
            ->assertSee('Please give your consent before continuing with verification.')
            ->set('consent', true)->call('verify')->assertHasNoErrors()
            ->assertSet('showAvailability', false)
            ->set('consent', false)->call('verify')
            ->assertHasErrors(['consent' => 'accepted'])->assertSet('showAvailability', false);
    }

    public function test_verification_is_forbidden_after_sign_out(): void
    {
        $this->actingAs(User::factory()->create());
        $component = Livewire::test(BvnVerification::class);
        Auth::logout();

        $component->call('verify')->assertForbidden();
    }

    private function fakeProvider(string $bvn): void
    {
        config(['services.verify.url' => 'https://verify.example.test', 'services.verify.token' => 'test-token']);
        ServicePrice::factory()->create(['service' => 'bvn-verification', 'method' => 'bvn', 'amount' => 0]);
        Http::preventStrayRequests();
        Http::fake(['https://verify.example.test/api/v1/verify' => Http::response([
            'status' => true, 'message' => ['idNumber' => $bvn, 'firstName' => 'Test', 'lastName' => 'Person'],
        ])]);
    }
}
