<?php

namespace Tests\Feature;

use App\Livewire\Personalization;
use App\Models\PersonalizationRequest;
use App\Models\ServicePrice;
use App\Models\SlipPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class PersonalizationSlipTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['regular'])]
    #[TestWith(['standard'])]
    #[TestWith(['premium'])]
    #[TestWith(['digital'])]
    public function test_successful_personalization_has_compact_slip_icons_and_private_pdf_downloads(string $slip): void
    {
        $record = PersonalizationRequest::factory()->create(['status' => 'successful', 'result' => $this->details()]);
        $this->actingAs($record->user);
        $url = route('personalization.slip', ['personalization' => $record->reference, 'slip' => $slip]);
        Livewire::test(Personalization::class)->call('viewResult', $record->reference)->assertHasNoErrors()
            ->assertSee('Download slips')->assertSee($url, false)->assertSee('h-7 w-11', false)->assertSee('Personalization portrait');
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertDownload('personalization-'.$slip.'-'.$record->reference.'.pdf');
        $this->assertStringStartsWith('%PDF-1.4', $response->getContent());
        $this->assertStringContainsString('SAMPLE', $response->getContent());
        if ($slip === 'regular' || $slip === 'digital') {
            $this->assertStringContainsString($record->tracking_id, $response->getContent());
        }
    }

    public function test_paid_slips_use_nin_prices_and_are_charged_once_per_personalization(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        $record = PersonalizationRequest::factory()->create(['user_id' => $user->id, 'status' => 'successful', 'result' => $this->details()]);
        ServicePrice::factory()->create(['service' => 'nin-slips', 'method' => 'premium', 'amount' => 7500]);
        $this->actingAs($user);
        $url = route('personalization.slip', ['personalization' => $record->reference, 'slip' => 'premium']);
        Livewire::test(Personalization::class)->call('viewResult', $record->reference)->assertSee('75.00')
            ->assertSee('expected_price', false);
        $this->get($url)->assertForbidden();
        $this->post($url, ['expected_price' => 7500])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(92500, $user->fresh()->wallet_balance);
        $purchase = SlipPurchase::query()->sole();
        $this->assertSame($record->id, $purchase->personalization_request_id);
        $this->assertNull($purchase->nin_verification_record_id);
        $this->assertNull($purchase->bvn_verification_record_id);
        $this->post($url, ['expected_price' => 7500])->assertOk();
        $this->get($url)->assertOk();
        $this->assertSame(92500, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('slip_purchases', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    #[TestWith(['pending'])]
    #[TestWith(['successful'])]
    #[TestWith(['in_progress'])]
    #[TestWith(['failed'])]
    public function test_personalization_without_successful_result_data_cannot_download_slips(string $status): void
    {
        $record = PersonalizationRequest::factory()->create(['status' => $status]);
        $this->actingAs($record->user)->get(route('personalization.slip', ['personalization' => $record->reference, 'slip' => 'regular']))
            ->assertNotFound();
        $this->assertDatabaseCount('slip_purchases', 0);
    }

    public function test_downloads_reject_guests_other_users_and_unknown_slips(): void
    {
        $record = PersonalizationRequest::factory()->create(['status' => 'successful', 'result' => $this->details()]);
        $url = route('personalization.slip', ['personalization' => $record->reference, 'slip' => 'regular']);
        $this->get($url)->assertRedirect();
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->post($url, ['expected_price' => 0])->assertNotFound();
        $this->actingAs($record->user)->get(route('personalization.slip', ['personalization' => $record->reference, 'slip' => 'unknown']))
            ->assertNotFound();
    }

    public function test_missing_photo_is_explicit_and_purchasing_unavailable_slips_is_blocked(): void
    {
        $record = PersonalizationRequest::factory()->create(['status' => 'successful', 'result' => [...$this->details(), 'photo' => null]]);
        ServicePrice::factory()->create(['service' => 'nin-slips', 'method' => 'premium', 'amount' => null]);
        $this->actingAs($record->user);
        Livewire::test(Personalization::class)->call('viewResult', $record->reference)
            ->assertSee('Photo unavailable')->assertSee('Unavailable')->assertDontSee('Personalization portrait');
        $this->post(route('personalization.slip', ['personalization' => $record->reference, 'slip' => 'premium']), ['expected_price' => 0])
            ->assertSessionHasErrors(['slipDownload']);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    private function details(): array
    {
        $photo = imagecreatetruecolor(60, 80);
        imagefill($photo, 0, 0, imagecolorallocate($photo, 120, 140, 160));
        ob_start();
        imagepng($photo);

        return [
            'firstName' => 'JOHN', 'middleName' => 'A', 'lastName' => 'SAMPLE',
            'idNumber' => '01234567890', 'dateOfBirth' => '1990-01-01', 'gender' => 'M',
            'photo' => 'data:image/png;base64,'.base64_encode((string) ob_get_clean()),
        ];
    }
}
