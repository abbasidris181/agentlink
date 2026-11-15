<?php

namespace Tests\Feature;

use App\Livewire\BvnVerification;
use App\Models\BvnVerificationRecord;
use App\Models\NinVerificationRecord;
use App\Models\ServicePrice;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class BvnProviderTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.verify.url' => 'https://verify.example.test/', 'services.verify.token' => 'test-token']);
    }

    public function test_bvn_posts_the_contract_charges_once_and_opens_private_results(): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        ServicePrice::factory()->create(['service' => 'bvn-verification', 'method' => 'bvn', 'amount' => 25000]);
        Http::preventStrayRequests();
        Http::fake(['https://verify.example.test/api/v1/verify' => Http::response($this->providerResponse())]);
        $this->actingAs($user);

        $component = Livewire::test(BvnVerification::class)->set('bvn', '22484232222')->set('consent', true)->call('verify')->assertHasNoErrors();
        $record = BvnVerificationRecord::query()->sole();
        $component->assertRedirect(route('bvn.result', ['verification' => $record->reference]));
        $this->assertSame('successful', $record->status);
        $this->assertSame('22484232222', $record->bvn);
        $this->assertSame(75000, $user->fresh()->wallet_balance);
        $this->assertStringContainsString('22484232222', $record->getRawOriginal('bvn'));
        $this->assertStringContainsString('TEST', $record->getRawOriginal('result'));
        $this->assertArrayNotHasKey('result', $record->toArray());
        $this->assertDatabaseHas('wallet_transactions', ['id' => $record->wallet_transaction_id, 'amount' => '250.00', 'service' => 'BVN']);
        $component->call('verify')->assertHasNoErrors();
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://verify.example.test/api/v1/verify'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === ['idNumber' => '22484232222', 'type' => 'bvn', 'consent' => true]);
        $this->get(route('bvn.result', ['verification' => $record->reference]))->assertOk()
            ->assertSee('Back to BVN')->assertSee('22484232222')->assertSee('Sample State')
            ->assertSeeInOrder(['Signature', 'Download slips', 'Personal information'])
            ->assertSee('BVN slip')->assertSee('Plastic BVN')->assertSee('Personalized PDF slips')
            ->assertSee('alt="Photo of', false)->assertSee('alt="Signature of', false)
            ->assertSee('<script>alert(1)</script>')->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('NIN V1')->assertDontSee('regular.png');
        $this->get(route('bvn.verify'))->assertOk()->assertSee($record->reference)->assertSee('Successful')->assertDontSee('No verifications yet');
    }

    #[TestWith(['rejected'])]
    #[TestWith(['timeout'])]
    #[TestWith(['server-error'])]
    #[TestWith(['unavailable'])]
    #[TestWith(['provider-balance'])]
    #[TestWith(['malformed'])]
    #[TestWith(['invalid-success'])]
    #[TestWith(['mismatched-bvn'])]
    public function test_failed_bvn_verifications_refund_the_original_charge(string $scenario): void
    {
        $user = User::factory()->create(['wallet_balance' => 100000]);
        ServicePrice::factory()->create(['service' => 'bvn-verification', 'method' => 'bvn', 'amount' => 25000]);
        $response = match ($scenario) {
            'rejected' => Http::response(['status' => false, 'message' => 'No record found']),
            'timeout' => Http::failedConnection(),
            'server-error' => Http::response(['status' => false, 'message' => 'Error'], 500),
            'unavailable' => Http::response(['status' => false, 'message' => 'Service unavailable'], 503),
            'provider-balance' => Http::response(['status' => false, 'message' => 'Insufficient balance']),
            'malformed' => Http::response('<html>Error</html>'),
            'invalid-success' => Http::response(['status' => true, 'message' => []]),
            'mismatched-bvn' => Http::response(['status' => true, 'message' => ['idNumber' => '11111111111', 'firstName' => 'Wrong', 'lastName' => 'Person']]),
        };
        Http::preventStrayRequests();
        Http::fake(['https://verify.example.test/api/v1/verify' => $response]);
        $this->actingAs($user);

        Livewire::test(BvnVerification::class)->set('bvn', '22484232222')->set('consent', true)->call('verify')
            ->assertHasErrors(['verification'])->assertSee('returned to your wallet');

        $record = BvnVerificationRecord::query()->sole();
        $this->assertSame('failed', $record->status);
        $this->assertSame(100000, $user->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', ['refund_of_id' => $record->wallet_transaction_id, 'amount' => '250.00']);
        $this->get(route('bvn.result', ['verification' => $record->reference]))->assertNotFound();
        Http::assertSentCount(1);
    }

    #[TestWith(['missing-config'])]
    #[TestWith(['missing-price'])]
    #[TestWith(['insufficient-balance'])]
    #[TestWith(['disabled'])]
    public function test_ineligible_bvn_requests_do_not_call_the_provider_or_debit_wallets(string $scenario): void
    {
        $user = User::factory()->create(['wallet_balance' => 100]);
        if ($scenario !== 'missing-price') {
            ServicePrice::factory()->create(['service' => 'bvn-verification', 'method' => 'bvn', 'amount' => 25000]);
        }
        if ($scenario === 'missing-config') {
            config(['services.verify.token' => '']);
        }
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAs($user);
        $component = Livewire::test(BvnVerification::class)->set('bvn', '22484232222')->set('consent', true);
        if ($scenario === 'disabled') {
            SiteSetting::factory()->create(['key' => 'service_disabled.bvn-verification', 'value' => '1']);
        }

        $component->call('verify')->assertHasErrors([$scenario === 'disabled' ? 'serviceAvailability' : 'verification']);

        $this->assertSame(100, $user->fresh()->wallet_balance);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('bvn_verification_records', 0);
        Http::assertNothingSent();
    }

    #[TestWith(['slip', 1])]
    #[TestWith(['plastic', 2])]
    public function test_bvn_pdf_downloads_are_private_and_have_the_required_card_sides(string $slip, int $images): void
    {
        $user = User::factory()->create();
        $record = BvnVerificationRecord::factory()->for($user)->create(['result' => $this->providerResponse()['message']]);
        $url = route('bvn.slip', ['verification' => $record->reference, 'slip' => $slip]);
        $this->get($url)->assertRedirect(route('home'));
        $this->actingAs($user);

        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')->assertDownload('bvn-'.$slip.'-'.$record->reference.'.pdf');

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertSame($images, substr_count($pdf, '/Subtype /Image'));
        $this->assertStringContainsString('/MediaBox [0 0 595.28 841.89]', $pdf);
        if (getenv('BVN_SLIP_QA_DIR')) {
            file_put_contents(getenv('BVN_SLIP_QA_DIR').'/'.$slip.'.pdf', $pdf);
        }

        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
    }

    public function test_results_do_not_leak_between_users_or_nin_and_bvn_services(): void
    {
        $owner = User::factory()->create();
        $bvn = BvnVerificationRecord::factory()->for($owner)->create();
        $nin = NinVerificationRecord::factory()->for($owner)->create();
        $url = route('bvn.result', ['verification' => $bvn->reference]);
        $this->get($url)->assertRedirect(route('home'));
        $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
        $this->actingAs($owner)->get(route('bvn.result', ['verification' => $nin->reference]))->assertNotFound();
        $this->get(route('nin.v1.result', ['verification' => $bvn->reference]))->assertNotFound();
        $this->get(route('bvn.slip', ['verification' => $bvn->reference, 'slip' => 'premium']))->assertNotFound();
    }

    /** @return array{status: bool, message: array<string, mixed>, transID: string} */
    private function providerResponse(): array
    {
        $canvas = imagecreatetruecolor(180, 240);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 120, 150, 180));
        ob_start();
        imagepng($canvas);
        $image = 'data:image/png;base64,'.base64_encode(ob_get_clean());

        return ['status' => true, 'message' => [
            'firstName' => 'TEST', 'middleName' => 'MIDDLE', 'lastName' => 'PERSON', 'idNumber' => '22484232222',
            'mobile' => '08012345678', 'dateOfBirth' => '20-01-1995', 'gender' => 'M',
            'address' => ['state' => 'Sample State', 'addressLine' => 'Sample Street'],
            'photo' => $image, 'signature' => $image, 'email' => 'person@example.test',
            'enrollmentBank' => 'Sample Bank', 'enrollmentBranch' => 'Sample Branch', 'registrationDate' => '01-01-2015',
            'extraField' => '<script>alert(1)</script>',
        ], 'transID' => 'bvn-provider-reference'];
    }
}
