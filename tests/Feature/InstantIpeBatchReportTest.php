<?php

namespace Tests\Feature;

use App\Livewire\InstantIpe;
use App\Models\InstantIpeRequest;
use App\Models\InstantIpeSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class InstantIpeBatchReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_clicking_a_batch_displays_formatted_results_and_the_complete_summary(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $owner = User::factory()->create();
        $batch = $this->batch($owner, [
            ['tracking_id' => '0TE86ZASLGEB93Y', 'reply' => 'S7Y0O0ZMD000B3Z', 'nin' => '98475747847', 'status' => 'successful'],
            ['tracking_id' => '0TE86ZASVJWUULC', 'reply' => '7Y0NYFBK003KPDL', 'nin' => '75758575858', 'status' => 'successful'],
            ['tracking_id' => '0TE86ZASRKZ0YAH', 'reply' => '0RK23JJS5R6U6FC', 'nin' => null, 'status' => 'successful'],
            ['tracking_id' => '0TE86ZAS3PJC2AY', 'reply' => '0SED13QSZAQASNR', 'nin' => '71732691150', 'status' => 'successful'],
            ['tracking_id' => '0T4RYKJSNVQHROK', 'reply' => 'Ready for Abis', 'nin' => null, 'status' => 'failed'],
        ]);
        $this->actingAs($owner);
        $form = Livewire::test(InstantIpe::class)->assertSee('View batch '.$batch->batch_id)
            ->call('viewBatch', $batch->batch_id)->assertSet('reportBatchId', '12345678')
            ->assertSee('Batch report')->assertSee('0TE86ZASLGEB93Y=S7Y0O0ZMD000B3Z=98475747847')
            ->assertSee('0TE86ZASVJWUULC=7Y0NYFBK003KPDL=75758575858')
            ->assertSee('0TE86ZASRKZ0YAH=0RK23JJS5R6U6FC')
            ->assertSee('0TE86ZAS3PJC2AY=0SED13QSZAQASNR=71732691150')
            ->assertSee('0T4RYKJSNVQHROK=Ready for Abis')
            ->assertSee('Summary:')->assertSee('Total Records:')->assertSee('Total Successful:')->assertSee('Total Failed:');
        $form->assertViewHas('batchReport', fn (array $report): bool => $report['total'] === 5 && $report['successful'] === 4 && $report['failed'] === 1 && $report['pending'] === 0);
        $form->assertDontSee('Total Pending:')->call('closeBatch')->assertSet('reportBatchId', null)->assertDontSee('Batch report');
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_batch_report_contains_all_records_across_history_pages_and_excludes_other_batches(): void
    {
        $owner = User::factory()->create();
        $batch = InstantIpeSubmission::factory()->create(['user_id' => $owner->id, 'mode' => 'bulk', 'batch_id' => '12345678']);
        $records = InstantIpeRequest::factory()->count(25)->create(['instant_ipe_submission_id' => $batch->id, 'user_id' => $owner->id, 'status' => 'successful', 'reply' => 'CLEARED']);
        $other = $this->batch($owner, [['status' => 'failed', 'reply' => 'OTHER BATCH REPLY']], '87654321');
        $this->actingAs($owner);
        $form = Livewire::test(InstantIpe::class)->assertDontSee($records->first()->tracking_id)
            ->call('viewBatch', '12345678')->assertSee($records->first()->tracking_id.'=CLEARED');
        $form->assertViewHas('batchReport', fn (array $report): bool => $report['total'] === 25 && $report['successful'] === 25 && ! str_contains($report['lines'], 'OTHER BATCH REPLY'));
    }

    #[TestWith(['87654321'])]
    #[TestWith(['00000000'])]
    #[TestWith(['invalid'])]
    public function test_other_users_batches_and_invalid_ids_cannot_be_opened(string $batchId): void
    {
        $this->batch(User::factory()->create(), [['status' => 'successful', 'reply' => 'PRIVATE REPLY', 'nin' => '01234567890']], '87654321');
        $this->actingAs(User::factory()->create());
        Livewire::test(InstantIpe::class)->assertDontSee('PRIVATE REPLY')->call('viewBatch', $batchId)->assertNotFound();
    }

    public function test_an_open_report_is_refreshed_with_new_results_and_pending_totals(): void
    {
        $owner = User::factory()->create();
        $batch = $this->batch($owner, [
            ['status' => 'successful', 'reply' => 'Done'], ['status' => 'failed', 'reply' => 'Failed'],
            ['status' => 'queued'], ['status' => 'submitting'], ['status' => 'pending'], ['status' => 'unconfirmed'],
        ]);
        $this->actingAs($owner);
        $form = Livewire::test(InstantIpe::class)->call('viewBatch', $batch->batch_id)->assertSee('Total Pending:');
        $form->assertViewHas('batchReport', fn (array $report): bool => $report['total'] === 6 && $report['successful'] === 1 && $report['failed'] === 1 && $report['pending'] === 4);
        $batch->requests->firstWhere('status', 'pending')->update(['status' => 'successful', 'reply' => 'New result', 'nin' => '01234567890']);
        $form->call('$refresh')->assertSee('New result=01234567890');
        $form->assertViewHas('batchReport', fn (array $report): bool => $report['successful'] === 2 && $report['pending'] === 3);
    }

    public function test_report_escapes_provider_content_and_preserves_one_line_per_tracking_id(): void
    {
        $owner = User::factory()->create();
        $batch = $this->batch($owner, [
            ['tracking_id' => 'ABC123DEF456GH7', 'status' => 'failed', 'reply' => "Ready\nfor Abis<script>alert(1)</script>"],
            ['tracking_id' => 'XYZ987UVW654ST3', 'status' => 'successful', 'reply' => null, 'nin' => '01234567890'],
        ]);
        $this->actingAs($owner);
        Livewire::test(InstantIpe::class)->call('viewBatch', $batch->batch_id)
            ->assertSee('ABC123DEF456GH7=Ready for Abis<script>alert(1)</script>')
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('XYZ987UVW654ST3==01234567890');
    }

    public function test_signing_out_blocks_access_to_an_already_open_batch_report(): void
    {
        $owner = User::factory()->create();
        $batch = $this->batch($owner, [['status' => 'pending']]);
        $this->actingAs($owner);
        $form = Livewire::test(InstantIpe::class)->call('viewBatch', $batch->batch_id);
        Auth::logout();
        $form->call('$refresh')->assertForbidden();
    }

    /** @param list<array<string, mixed>> $records */
    private function batch(User $owner, array $records, string $batchId = '12345678'): InstantIpeSubmission
    {
        $batch = InstantIpeSubmission::factory()->create(['user_id' => $owner->id, 'mode' => 'bulk', 'batch_id' => $batchId]);
        foreach ($records as $record) {
            InstantIpeRequest::factory()->create(['instant_ipe_submission_id' => $batch->id, 'user_id' => $owner->id, ...$record]);
        }

        return $batch->load('requests');
    }
}
