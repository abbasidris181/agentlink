<?php

namespace App\Jobs;

use App\Models\MonnifyPayment;
use App\MonnifyFundingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class VerifyMonnifyPayment implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 50;

    /** @var list<int> */
    public array $backoff = [15, 60, 180, 600];

    public function __construct(public int $paymentId)
    {
        $this->onConnection('database');
    }

    public function handle(MonnifyFundingService $funding): void
    {
        $funding->verifyPayment($this->paymentId);
    }

    public function failed(?Throwable $exception): void
    {
        MonnifyPayment::query()->whereKey($this->paymentId)->whereNotIn('status', ['credited', 'ignored'])->update([
            'status' => 'review', 'last_error' => 'Payment verification needs review. Contact support.',
        ]);
    }
}
