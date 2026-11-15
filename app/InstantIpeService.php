<?php

namespace App;

use App\Jobs\CheckInstantIpeStatus;
use App\Jobs\SubmitInstantIpeRequest;
use App\Models\AdminAudit;
use App\Models\InstantIpeRequest;
use App\Models\InstantIpeSubmission;
use App\Models\ServicePrice;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InstantIpeService
{
    /** @param list<string> $trackingIds */
    public function submit(User $user, string $mode, array $trackingIds, string $requestKey): InstantIpeSubmission
    {
        Validator::make(compact('mode', 'trackingIds', 'requestKey'), [
            'mode' => ['required', Rule::in(['single', 'bulk'])],
            'trackingIds' => ['required', 'array', 'min:1'],
            'trackingIds.*' => ['required', 'string', 'regex:/\A[A-Z0-9]{15}\z/', 'distinct:strict'],
            'requestKey' => ['required', 'uuid'],
        ])->validate();
        if ($mode === 'single' && count($trackingIds) !== 1) {
            throw ValidationException::withMessages(['trackingInput' => 'Single mode accepts one tracking ID.']);
        }
        $this->assertConfigured();
        try {
            return DB::transaction(function () use ($user, $mode, $trackingIds, $requestKey): InstantIpeSubmission {
                $owner = User::query()->lockForUpdate()->findOrFail($user->id);
                abort_unless($owner->is_active && ! $owner->needsEmailVerification(), 403);
                ServiceAvailability::validateSubmission('instant-ipe', $mode);
                $existing = InstantIpeSubmission::query()->where('request_key', $requestKey)->first();
                if ($existing !== null) {
                    if ($existing->user_id !== $owner->id || $existing->mode !== $mode
                        || $existing->requests()->orderBy('id')->pluck('tracking_id')->all() !== array_values($trackingIds)) {
                        throw ValidationException::withMessages(['trackingInput' => 'This submission reference has already been used. Reload the page.']);
                    }

                    return $existing;
                }
                $price = ServicePrice::amountFor('instant-ipe', $mode);
                if ($price === null) {
                    throw ValidationException::withMessages(['trackingInput' => 'Pricing is not configured for this option yet.']);
                }
                if ($price > 0 && count($trackingIds) > intdiv($owner->wallet_balance, $price)) {
                    throw ValidationException::withMessages(['trackingInput' => 'Your wallet balance is insufficient for this submission.']);
                }
                if (InstantIpeRequest::query()->where('user_id', $owner->id)->where('status', 'unconfirmed')->whereIn('tracking_id', $trackingIds)->exists()) {
                    throw ValidationException::withMessages(['trackingInput' => 'One of these tracking IDs already has a request that needs confirmation in your history.']);
                }
                $batch = null;
                if (count($trackingIds) >= 2) {
                    do {
                        $batch = (string) random_int(10000000, 99999999);
                    } while (InstantIpeSubmission::query()->where('batch_id', $batch)->exists());
                }
                $submission = InstantIpeSubmission::create(['user_id' => $owner->id, 'request_key' => $requestKey, 'batch_id' => $batch, 'mode' => $mode]);
                foreach ($trackingIds as $trackingId) {
                    $record = InstantIpeRequest::create(['instant_ipe_submission_id' => $submission->id, 'user_id' => $owner->id, 'reference' => (string) Str::ulid(), 'tracking_id' => $trackingId, 'active_tracking_id' => null, 'price' => $price, 'status' => 'queued']);
                    $oldBalance = $owner->wallet_balance;
                    $owner->wallet_balance -= $price;
                    WalletTransaction::create(['instant_ipe_request_id' => $record->id, 'reference' => $record->reference, 'request_key' => (string) Str::uuid(), 'user_id' => $owner->id, 'actor_id' => $owner->id, 'service' => 'Instant IPE', 'type' => 'service_debit', 'status' => 'completed', 'amount' => $price, 'old_balance' => $oldBalance, 'new_balance' => $owner->wallet_balance, 'reason' => 'Instant IPE request '.$record->reference]);
                    SubmitInstantIpeRequest::dispatch($record->id);
                }
                $owner->save();
                AdminAudit::create(['actor_id' => $owner->id, 'user_id' => $owner->id, 'event' => 'instant_ipe.submitted', 'details' => ['submission_id' => $submission->id, 'count' => count($trackingIds)]]);

                return $submission;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['trackingInput' => 'A duplicate submission was prevented. Review your history before trying again.']);
        }
    }

    public function submitRequest(int $requestId): void
    {
        $record = DB::transaction(function () use ($requestId): ?InstantIpeRequest {
            $record = InstantIpeRequest::query()->lockForUpdate()->find($requestId);
            if ($record === null || $record->status !== 'queued' || $record->dispatch_started_at !== null) {
                return null;
            }
            $record->status = 'submitting';
            $record->dispatch_started_at = now();
            $this->scheduleCheck($record);
            $record->save();

            return $record;
        }, 3);
        if ($record === null) {
            return;
        }
        $result = $this->providerRequest('clearance', $record->tracking_id, $record->id);
        $this->saveResult($record->id, $result, null, true);
    }

    /** Return false only when a short lease or cooldown requires another attempt. */
    public function checkRequest(int $requestId, ?int $dueAt = null): bool
    {
        $snapshot = InstantIpeRequest::query()->find($requestId);
        if ($snapshot !== null && $snapshot->status === 'failed') {
            if ($snapshot->refunded_at === null) {
                $this->saveResult($requestId, [], null, false);
            }

            return true;
        }
        $claim = DB::transaction(function () use ($requestId, $dueAt): array {
            $record = InstantIpeRequest::query()->lockForUpdate()->find($requestId);
            if ($record === null || in_array($record->status, ['successful', 'failed'], true) || $record->dispatch_started_at === null) {
                return ['record' => null, 'busy' => false];
            }
            if ($dueAt !== null && $record->next_check_at?->timestamp !== $dueAt) {
                return ['record' => null, 'busy' => false];
            }
            if (($dueAt !== null && $dueAt > now()->timestamp)
                || $record->check_lease_until?->isFuture()
                || ($record->status === 'submitting' && $record->dispatch_started_at->gt(now()->subMinute()))
                || $record->last_checked_at?->gt(now()->subSeconds(15))) {
                return ['record' => null, 'busy' => true];
            }
            $record->check_token = (string) Str::uuid();
            $record->check_lease_until = now()->addMinute();
            $record->save();

            return ['record' => $record, 'busy' => false];
        }, 3);
        if ($claim['record'] !== null) {
            $record = $claim['record'];
            $result = $this->providerRequest('clearance/status', $record->tracking_id, $record->id);
            $this->saveResult($record->id, $result, $record->check_token, false, $dueAt !== null);
        }

        return ! $claim['busy'];
    }

    public function refundRequest(User $actor, int $requestId): void
    {
        $this->authorizeAdminAction($actor, 'requests.refund');
        $ownerId = InstantIpeRequest::query()->findOrFail($requestId)->user_id;
        DB::transaction(function () use ($actor, $requestId, $ownerId): void {
            $owner = User::query()->lockForUpdate()->findOrFail($ownerId);
            $record = InstantIpeRequest::query()->lockForUpdate()->findOrFail($requestId);
            $this->authorizeAdminAction($actor, 'requests.refund');
            $charge = $record->walletTransaction()->lockForUpdate()->first();
            if ($charge === null || $charge->user_id !== $owner->id || $charge->type !== 'service_debit' || $charge->status !== 'completed' || $charge->amount < 1) {
                throw ValidationException::withMessages(['refund' => 'This request has no refundable payment.']);
            }
            $this->refund($record, $owner, $actor);
            if ($record->refunded_at === null) {
                throw ValidationException::withMessages(['refund' => 'The refund would exceed the supported wallet balance.']);
            }
            $record->status = 'failed';
            $record->next_check_at = null;
            $record->check_token = null;
            $record->check_lease_until = null;
            $record->save();
        }, 3);
    }

    public function replyToRequest(User $actor, int $requestId, string $reply): void
    {
        $this->authorizeAdminAction($actor, 'requests.manage');
        $reply = trim($reply);
        Validator::make(['reply' => $reply], ['reply' => ['required', 'string', 'max:10000']])->validate();
        DB::transaction(function () use ($actor, $requestId, $reply): void {
            $record = InstantIpeRequest::query()->lockForUpdate()->findOrFail($requestId);
            $this->authorizeAdminAction($actor, 'requests.manage');
            $record->reply = $reply;
            $record->admin_replied_at = now();
            $record->save();
            AdminAudit::create(['actor_id' => $actor->id, 'user_id' => $record->user_id, 'event' => 'instant_ipe.replied', 'details' => ['reference' => $record->reference]]);
        }, 3);
    }

    public function completeRequest(User $actor, int $requestId): void
    {
        $this->authorizeAdminAction($actor, 'requests.manage');
        DB::transaction(function () use ($actor, $requestId): void {
            $record = InstantIpeRequest::query()->lockForUpdate()->findOrFail($requestId);
            $this->authorizeAdminAction($actor, 'requests.manage');
            if ($record->status === 'failed' || $record->refunded_at !== null) {
                throw ValidationException::withMessages(['complete' => 'Failed or refunded requests cannot be marked completed.']);
            }
            if ($record->status === 'successful') {
                return;
            }
            $previous = $record->status;
            $record->status = 'successful';
            $record->next_check_at = null;
            $record->check_token = null;
            $record->check_lease_until = null;
            $record->last_error = null;
            $record->save();
            AdminAudit::create(['actor_id' => $actor->id, 'user_id' => $record->user_id, 'event' => 'instant_ipe.completed', 'details' => ['reference' => $record->reference, 'old_status' => $previous]]);
        }, 3);
    }

    private function authorizeAdminAction(User $actor, string $ability): void
    {
        Gate::forUser($actor)->authorize('access-admin');
        Gate::forUser($actor)->authorize('transactions.view');
        Gate::forUser($actor)->authorize($ability);
    }

    public function assertConfigured(): void
    {
        $url = config('services.tracking_id.tracking_url');
        $token = config('services.tracking_id.tracking_token');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true) || ! is_string($token) || trim($token) === '') {
            throw ValidationException::withMessages(['trackingInput' => 'Instant IPE provider connection is not configured. Please try again later.']);
        }
    }

    /** @return array{state: string, provider_status: ?string, reply: ?string, nin: ?string, error: ?string} */
    private function providerRequest(string $endpoint, string $trackingId, int $requestId): array
    {
        $unknown = ['state' => 'unconfirmed', 'provider_status' => null, 'reply' => null, 'nin' => null, 'error' => 'You can check status again.'];
        try {
            $this->assertConfigured();
            $response = Http::asJson()->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(25)
                ->post(rtrim(config('services.tracking_id.tracking_url'), '/').'/api/'.$endpoint, ['token' => config('services.tracking_id.tracking_token'), 'tracking_id' => $trackingId]);
        } catch (ConnectionException|ValidationException $exception) {
            ProviderResponseLogger::failure('instant-ipe', $exception, ['request_id' => $requestId, 'endpoint' => $endpoint]);

            return $unknown;
        }
        $token = config('services.tracking_id.tracking_token');
        ProviderResponseLogger::response('instant-ipe', $response, ['request_id' => $requestId, 'endpoint' => $endpoint], [$token]);

        if (! $response->successful()) {
            return $unknown;
        }
        $body = $response->json();
        if (! is_array($body) || ! empty($body['error'])) {
            return $unknown;
        }
        if (array_key_exists('response', $body)) {
            $body = is_string($body['response']) ? json_decode($body['response'], true) : $body['response'];
        }
        if (! is_array($body)) {
            return $unknown;
        }
        $providerStatus = is_string($body['status'] ?? null) ? trim($body['status']) : null;
        $state = match (strtolower($providerStatus ?? '')) {
            'successful' => 'successful', 'failed' => 'failed', 'new', 'pending' => 'pending', default => null,
        };
        if ($state === null && $endpoint === 'clearance' && is_string($body['success'] ?? null) && trim($body['success']) !== '') {
            $state = 'pending';
        }
        if ($state === null) {
            return $unknown;
        }
        $nin = isset($body['nin']) && is_scalar($body['nin']) ? (string) $body['nin'] : null;

        return ['state' => $state, 'provider_status' => $providerStatus === null ? null : substr($providerStatus, 0, 80),
            'reply' => is_string($body['reply'] ?? null) ? Str::limit($body['reply'], 10000, '') : null,
            'nin' => $nin !== null && preg_match('/\A[0-9]{11}\z/', $nin) === 1 ? $nin : null, 'error' => null];
    }

    /** @param array{state?: string, provider_status?: ?string, reply?: ?string, nin?: ?string, error?: ?string} $result */
    private function saveResult(int $requestId, array $result, ?string $checkToken, bool $submission, bool $automatic = false): void
    {
        $ownerId = InstantIpeRequest::query()->findOrFail($requestId)->user_id;
        DB::transaction(function () use ($requestId, $ownerId, $result, $checkToken, $submission, $automatic): void {
            $owner = User::query()->lockForUpdate()->findOrFail($ownerId);
            $record = InstantIpeRequest::query()->lockForUpdate()->findOrFail($requestId);
            if ($record->status === 'successful' || ($record->status === 'failed' && $record->refunded_at !== null)) {
                return;
            }
            if ($record->status !== 'failed') {
                if ((! $submission && $record->check_token !== $checkToken) || ($submission && $record->last_checked_at !== null)) {
                    return;
                }
                $record->status = $result['state'];
                $record->last_error = $result['error'];
                if ($record->status !== 'unconfirmed') {
                    $record->provider_status = $result['provider_status'];
                    if ($record->admin_replied_at === null) {
                        $record->reply = $result['reply'];
                    }
                    $record->nin = $result['nin'];
                }
                if ($submission && $record->status !== 'unconfirmed') {
                    $record->submitted_at = now();
                }
                if (! $submission) {
                    $record->last_checked_at = now();
                    $record->check_token = null;
                    $record->check_lease_until = null;
                }
            }
            if ($record->status === 'failed') {
                $this->refund($record, $owner);
            }
            if ($record->status === 'successful' || $record->refunded_at !== null) {
                $record->next_check_at = null;
            } elseif ($submission || $automatic || $record->next_check_at === null || $record->status === 'failed') {
                $this->scheduleCheck($record);
            }
            $record->save();
        }, 3);
    }

    private function scheduleCheck(InstantIpeRequest $record): void
    {
        $record->next_check_at = now()->addMinutes(25)->startOfSecond();
        CheckInstantIpeStatus::dispatch($record->id, $record->next_check_at->timestamp)->delay($record->next_check_at);
    }

    private function refund(InstantIpeRequest $record, User $owner, ?User $actor = null): void
    {
        $charge = $record->walletTransaction()->lockForUpdate()->firstOrFail();
        $existing = $charge->refund()->first();
        if ($existing !== null || $charge->amount === 0) {
            $record->refunded_at = $existing?->created_at ?? now();
            $record->active_tracking_id = null;
            $record->last_error = null;

            return;
        }
        if ($owner->wallet_balance + $charge->amount > Money::MAX_MINOR) {
            $record->last_error = 'Refund pending: wallet balance limit reached.';

            return;
        }
        $oldBalance = $owner->wallet_balance;
        $owner->wallet_balance += $charge->amount;
        $owner->save();
        $refund = WalletTransaction::create(['refund_of_id' => $charge->id, 'reference' => (string) Str::ulid(), 'request_key' => (string) Str::uuid(), 'user_id' => $owner->id, 'actor_id' => $actor?->id ?? $charge->actor_id, 'service' => 'Instant IPE refund', 'type' => 'credit', 'status' => 'completed', 'amount' => $charge->amount, 'old_balance' => $oldBalance, 'new_balance' => $owner->wallet_balance, 'reason' => 'Refund for Instant IPE request '.$record->reference]);
        $record->refunded_at = now();
        $record->active_tracking_id = null;
        $record->last_error = null;
        AdminAudit::create(['actor_id' => $actor?->id ?? $charge->actor_id, 'user_id' => $owner->id, 'event' => 'instant_ipe.refunded', 'details' => ['reference' => $record->reference, 'refund_reference' => $refund->reference, 'amount' => $charge->amount, 'automatic' => $actor === null]]);
    }
}
