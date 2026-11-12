<?php

namespace App;

use App\Models\AdminAudit;
use App\Models\PersonalizationRequest;
use App\Models\ServicePrice;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalizationService
{
    public function submit(User $user, string $trackingId): PersonalizationRequest
    {
        ServiceAvailability::validateSubmission('personalization', 'tracking');
        $trackingId = trim($trackingId);
        Validator::make(['trackingId' => $trackingId], ['trackingId' => ['required', 'string', 'regex:/\A[A-Za-z0-9]{15}\z/']])->validate();
        $this->connection();
        [$record, $created] = DB::transaction(function () use ($user, $trackingId): array {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->authorize($owner);
            ServiceAvailability::validateSubmission('personalization', 'tracking');
            $hash = hash_hmac('sha256', $trackingId, config('app.key'));
            $existing = PersonalizationRequest::query()->where('user_id', $owner->id)->where('tracking_hash', $hash)->first();
            if ($existing !== null) {
                return [$existing, false];
            }

            $price = ServicePrice::amountFor('personalization', 'tracking');
            if ($price === null) {
                throw ValidationException::withMessages(['personalization' => 'Pricing is not configured for personalization yet.']);
            }
            if ($owner->wallet_balance < $price) {
                throw ValidationException::withMessages(['personalization' => 'Your wallet balance is insufficient. Fund your wallet before submitting.']);
            }
            $record = PersonalizationRequest::create([
                'user_id' => $owner->id, 'reference' => (string) Str::ulid(),
                'tracking_id' => $trackingId, 'tracking_hash' => $hash, 'status' => 'pending',
            ]);
            $oldBalance = $owner->wallet_balance;
            $owner->wallet_balance -= $price;
            $owner->save();
            WalletTransaction::create([
                'reference' => $record->reference, 'request_key' => (string) Str::uuid(), 'user_id' => $owner->id,
                'actor_id' => $owner->id, 'service' => 'Personalization', 'type' => 'service_debit',
                'status' => 'completed', 'amount' => $price, 'old_balance' => $oldBalance,
                'new_balance' => $owner->wallet_balance, 'reason' => 'Personalization request '.$record->reference,
            ]);
            AdminAudit::create(['actor_id' => $owner->id, 'user_id' => $owner->id, 'event' => 'personalization.submitted', 'details' => ['reference' => $record->reference, 'amount' => $price]]);

            return [$record, true];
        }, 3);
        if (! $created) {
            return $record;
        }

        return $this->send($record, false);
    }

    public function check(User $user, string $reference): PersonalizationRequest
    {
        ServiceAvailability::assertEnabled('personalization');
        $this->authorize($user->fresh());
        $record = PersonalizationRequest::query()->where('user_id', $user->id)->where('reference', $reference)->firstOrFail();
        $this->connection();
        $record = DB::transaction(function () use ($user, $record): PersonalizationRequest {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->authorize($owner);
            $current = PersonalizationRequest::query()->lockForUpdate()->findOrFail($record->id);
            if ($current->last_checked_at !== null && $current->last_checked_at->greaterThan(now()->subSeconds(10))) {
                throw ValidationException::withMessages(['personalization' => 'Please wait 10 seconds before checking this request again.']);
            }
            $current->last_checked_at = now();
            $current->save();

            return $current;
        }, 3);

        return $this->send($record, true);
    }

    private function authorize(User $user): void
    {
        abort_unless($user->is_active && ! $user->needsEmailVerification(), 403);
    }

    /** @return array{0: string, 1: string} */
    private function connection(): array
    {
        $url = config('services.tracking_id.tracking_url');
        $token = config('services.tracking_id.tracking_token');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
            || ! is_string($token) || trim($token) === '') {
            throw ValidationException::withMessages(['personalization' => 'Personalization is not configured. Please try again later.']);
        }

        return [rtrim($url, '/'), $token];
    }

    private function send(PersonalizationRequest $record, bool $checking): PersonalizationRequest
    {
        [$url, $token] = $this->connection();
        $body = null;
        $httpStatus = null;
        $transportError = null;
        try {
            $response = Http::asJson()->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(30)
                ->post($url.'/api/personalization'.($checking ? '/check' : ''), [
                    'token' => $token, 'tracking_id' => $record->tracking_id,
                    ...($checking ? [] : ['type' => 'info']),
                ]);
            $httpStatus = $response->status();
            if ($response->successful()) {
                $body = $response->json();
            } else {
                $transportError = 'http_error';
            }
        } catch (ConnectionException) {
            $transportError = 'connection_failure';
        }

        return DB::transaction(function () use ($record, $body, $checking, $httpStatus, $transportError): PersonalizationRequest {
            $owner = User::query()->lockForUpdate()->findOrFail($record->user_id);
            $current = PersonalizationRequest::query()->lockForUpdate()->findOrFail($record->id);
            $responseError = $this->responseError($current, $body, $checking);
            if ($responseError !== null) {
                Log::warning('Personalization provider response could not be confirmed.', [
                    'request_id' => $current->id,
                    'operation' => $checking ? 'check' : 'submission',
                    'http_status' => $httpStatus,
                    'reason' => $transportError ?? $responseError,
                    'success_type' => get_debug_type(is_array($body) ? ($body['success'] ?? null) : null),
                    'provider_id_type' => get_debug_type(is_array($body) ? ($body['id'] ?? null) : null),
                ]);
                $current->last_error = $checking
                    ? 'The provider status could not be confirmed. Check the result again shortly.'
                    : 'Submission is pending confirmation. Use Check status to confirm the request.';
                $current->save();

                return $current;
            }
            $current->provider_id = (string) $body['id'];
            $current->provider_status = $body['status'];
            $current->status = match ($body['status']) {
                'Successful' => 'successful',
                'Failed' => 'failed',
                default => 'in_progress',
            };
            if ($checking) {
                $current->result = $current->status === 'successful' ? $body['data'] : null;
            }
            $current->last_error = null;
            if ($current->status === 'failed') {
                $charge = WalletTransaction::query()->where('reference', $current->reference)->where('user_id', $owner->id)
                    ->where('service', 'Personalization')->where('type', 'service_debit')->lockForUpdate()->first();
                if ($charge !== null && $charge->amount > 0 && ! $charge->refund()->exists() && $owner->wallet_balance + $charge->amount <= Money::MAX_MINOR) {
                    $oldBalance = $owner->wallet_balance;
                    $owner->wallet_balance += $charge->amount;
                    $owner->save();
                    WalletTransaction::create([
                        'refund_of_id' => $charge->id, 'reference' => (string) Str::ulid(), 'request_key' => (string) Str::uuid(),
                        'user_id' => $owner->id, 'actor_id' => $owner->id, 'service' => 'Personalization refund',
                        'type' => 'credit', 'status' => 'completed', 'amount' => $charge->amount,
                        'old_balance' => $oldBalance, 'new_balance' => $owner->wallet_balance,
                        'reason' => 'Refund for personalization request '.$current->reference,
                    ]);
                }
            }
            $current->save();

            return $current;
        }, 3);
    }

    private function responseError(PersonalizationRequest $record, mixed $body, bool $checking): ?string
    {
        if (! is_array($body)) {
            return 'invalid_json';
        }
        if (($body['success'] ?? null) !== true) {
            return 'provider_rejected';
        }
        if (($body['tracking_id'] ?? null) !== $record->tracking_id) {
            return 'tracking_mismatch';
        }
        $providerId = $body['id'] ?? null;
        if ((! is_int($providerId) && ! is_string($providerId)) || preg_match('/\A[1-9][0-9]{0,79}\z/', (string) $providerId) !== 1) {
            return 'invalid_request_id';
        }
        if ($record->provider_id !== null && $record->provider_id !== (string) $providerId) {
            return 'request_id_mismatch';
        }
        if (! in_array($body['status'] ?? null, ['In-Progress', 'Successful', 'Failed'], true)) {
            return 'unknown_status';
        }
        if (! $checking || $body['status'] !== 'Successful') {
            return null;
        }
        $data = $body['data'] ?? null;
        $validResult = is_array($data) && ! array_is_list($data)
            && is_string($data['idNumber'] ?? null) && preg_match('/\A[0-9]{11}\z/', $data['idNumber']) === 1
            && is_string($data['firstName'] ?? null) && trim($data['firstName']) !== ''
            && is_string($data['lastName'] ?? null) && trim($data['lastName']) !== ''
            && (! isset($data['tracking_id']) || $data['tracking_id'] === $record->tracking_id);

        return $validResult ? null : 'invalid_result';
    }
}
