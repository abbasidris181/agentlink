<?php

namespace App\Jobs;

use App\InstantIpeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckInstantIpeStatus implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 40;

    public int $backoff = 60;

    public function __construct(public int $requestId, public int $dueAt)
    {
        $this->onConnection('instant_ipe');
        $this->onQueue('instant-ipe-status');
        $this->beforeCommit();
    }

    public function handle(InstantIpeService $service): void
    {
        if (! $service->checkRequest($this->requestId, $this->dueAt)) {
            $this->release(60);
        }
    }
}
