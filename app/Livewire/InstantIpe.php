<?php

namespace App\Livewire;

use App\InstantIpeService;
use App\Models\InstantIpeRequest;
use App\Models\InstantIpeSubmission;
use App\Models\ServicePrice;
use App\ServiceAvailability;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class InstantIpe extends Component
{
    use WithPagination;

    #[Locked]
    public string $requestKey = '';

    #[Locked]
    public ?string $reportBatchId = null;

    #[Locked]
    public int $totalCharged = 0;

    public string $mode = 'single';

    public string $trackingInput = '';

    /** @var list<string> */
    #[Locked]
    public array $trackingIds = [];

    #[Locked]
    public ?string $batchId = null;

    #[Locked]
    public string $preparedInput = '';

    #[Locked]
    public bool $showAvailability = false;

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
        ServiceAvailability::assertEnabled('instant-ipe');
        $this->requestKey = (string) Str::uuid();
    }

    public function hydrate(): void
    {
        abort_unless(Auth::check(), 403);
    }

    public function updatedMode(): void
    {
        $this->reset('trackingInput', 'trackingIds', 'batchId', 'preparedInput', 'showAvailability');
        $this->resetValidation();
    }

    public function submit(InstantIpeService $service): void
    {
        abort_unless(Auth::check(), 403);
        $this->showAvailability = false;
        ServiceAvailability::validateSubmission('instant-ipe', $this->mode);

        $this->reset('trackingIds', 'batchId', 'preparedInput', 'showAvailability');
        $this->trackingInput = strtoupper(trim($this->trackingInput));

        if ($this->trackingInput !== '') {
            if ($this->mode === 'bulk') {
                $tokens = preg_split('/[\s,;]+/', $this->trackingInput, -1, PREG_SPLIT_NO_EMPTY);

                foreach ($tokens as $token) {
                    $parts = preg_match('/\A[A-Z0-9]+\z/', $token) === 1 ? str_split($token, 15) : [$token];
                    array_push($this->trackingIds, ...$parts);
                }
            } else {
                $this->trackingIds = [$this->trackingInput];
            }
        }

        $this->trackingInput = implode("\n", $this->trackingIds);

        $this->validate([
            'mode' => ['required', 'in:single,bulk'],
            'trackingIds' => ['required', 'array', 'min:1'],
            'trackingIds.*' => ['required', 'string', 'regex:/\A[A-Z0-9]{15}\z/', 'distinct:strict'],
        ], [
            'trackingIds.required' => 'Enter at least one 15-character tracking ID.',
            'trackingIds.*.regex' => 'Tracking ID on line :position must contain exactly 15 letters or digits.',
        ]);

        $submission = $service->submit(Auth::user(), $this->mode, $this->trackingIds, $this->requestKey);
        $this->batchId = $submission->batch_id;
        $this->totalCharged = $submission->requests->sum('price');
        $this->requestKey = (string) Str::uuid();
        $this->resetPage();

        $this->reset('trackingInput', 'preparedInput');
        $this->showAvailability = true;
    }

    public function checkStatus(int $requestId, InstantIpeService $service): void
    {
        abort_unless(Auth::check(), 403);
        ServiceAvailability::assertEnabled('instant-ipe');
        $record = InstantIpeRequest::query()->where('user_id', Auth::id())->findOrFail($requestId);
        $this->resetValidation('statusCheck');
        if (! $record->canCheck()) {
            $this->addError('statusCheck', 'This request is queued, still submitting, or already finished.');

            return;
        }
        $rateKey = 'instant-ipe-check:'.Auth::id();
        if (RateLimiter::tooManyAttempts($rateKey, 20)) {
            $this->addError('statusCheck', 'Too many status checks. Please wait a minute.');

            return;
        }
        RateLimiter::hit($rateKey, 60);
        if (! $service->checkRequest($record->id)) {
            $this->addError('statusCheck', 'A status check is already running or was completed recently. Please wait a few seconds.');
        }
    }

    public function viewBatch(string $batchId): void
    {
        abort_unless(Auth::check(), 403);
        abort_unless(preg_match('/\A[0-9]{8}\z/', $batchId) === 1, 404);
        InstantIpeSubmission::query()->where('user_id', Auth::id())->where('batch_id', $batchId)->firstOrFail();
        $this->reportBatchId = $batchId;
    }

    public function closeBatch(): void
    {
        $this->reportBatchId = null;
    }

    /** @return array{batch_id: string, lines: string, total: int, successful: int, failed: int, pending: int}|null */
    private function batchReport(): ?array
    {
        if ($this->reportBatchId === null) {
            return null;
        }
        $batch = InstantIpeSubmission::query()->where('user_id', Auth::id())->where('batch_id', $this->reportBatchId)->firstOrFail();
        $records = $batch->requests()->where('user_id', Auth::id())->orderBy('id')->get();
        $successful = $records->where('status', 'successful')->count();
        $failed = $records->where('status', 'failed')->count();
        $lines = $records->map(function (InstantIpeRequest $request): string {
            $line = $request->tracking_id;
            $reply = $request->reply === null ? '' : preg_replace('/\R/u', ' ', $request->reply);
            if ($reply !== '' || $request->nin !== null) {
                $line .= '='.$reply;
                if ($request->nin !== null) {
                    $line .= '='.$request->nin;
                }
            }

            return $line;
        })->implode("\n");

        return ['batch_id' => $batch->batch_id, 'lines' => $lines, 'total' => $records->count(), 'successful' => $successful, 'failed' => $failed, 'pending' => $records->count() - $successful - $failed];
    }

    public function render(): View
    {
        return view('livewire.instant-ipe', ['canSubmit' => ServiceAvailability::canSubmit('instant-ipe', $this->mode), 'batchReport' => $this->batchReport(), 'price' => ServicePrice::amountFor('instant-ipe', $this->mode), 'history' => InstantIpeRequest::query()->with('submission:id,batch_id')->where('user_id', Auth::id())->latest('id')->paginate(20)]);
    }
}
