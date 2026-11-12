<?php

namespace App;

use App\Models\BvnVerificationRecord;
use App\Models\NinVerificationRecord;
use App\Models\ServicePrice;
use App\Models\User;
use App\Models\VerificationRecord;
use App\Models\WalletTransaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VerificationService
{
    public function verify(User $user, string $identifier, string $requestKey, string $method = 'nin', string $version = 'v1'): VerificationRecord
    {
        Validator::make(compact('identifier', 'requestKey', 'method', 'version'), [
            'method' => ['required', 'in:nin,phone,bvn'],
            'version' => ['required', 'in:v1,v2,v3'],

            'identifier' => ['required', 'string', $method === 'phone' ? 'regex:/\A(?:0[1-9][0-9]{9}|(?:\+?234)?[1-9][0-9]{9})\z/' : 'regex:/\A[0-9]{11}\z/'],
            'requestKey' => ['required', 'uuid'],
        ])->validate();
        $recordClass = $method === 'bvn' ? BvnVerificationRecord::class : NinVerificationRecord::class;
        $service = $method === 'bvn' ? 'bvn-verification' : 'nin-'.$version;
        $label = $method === 'bvn' ? 'BVN' : 'NIN '.strtoupper($version);
        $identifierField = $method === 'bvn' ? 'bvn' : 'nin';
        if ($method === 'phone') {
            $identifier = '0'.substr($identifier, -10);
        }
        $url = config('services.verify.url');
        $token = config('services.verify.token');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || ! is_string($token) || trim($token) === '') {
            throw ValidationException::withMessages(['verification' => $label.' verification is not configured. Please try again later.']);
        }

        [$record, $created] = DB::transaction(function () use ($user, $identifier, $requestKey, $method, $recordClass, $service, $label, $identifierField, $version): array {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($owner->is_active && ! $owner->needsEmailVerification(), 403);
            ServiceAvailability::validateSubmission($service, $method);
            $existing = $recordClass::query()->where('request_key', $requestKey)->first();
            if ($existing !== null) {
                $existingIdentifier = $method === 'phone' ? $existing->phone : $existing->{$identifierField};
                if ($existing->user_id !== $owner->id || $existing->method !== $method || ($method !== 'bvn' && $existing->version !== $version) || $existingIdentifier !== $identifier) {
                    throw ValidationException::withMessages(['verification' => 'This request reference has already been used. Reload the page.']);
                }

                return [$existing, false];
            }
            $pricing = ServicePrice::query()->where('service', $service)->where('method', $method)->first();
            $price = $pricing?->amount;
            if ($price === null) {
                throw ValidationException::withMessages(['verification' => 'Pricing is not configured for '.$label.' yet.']);
            }
            if ($owner->wallet_balance < $price) {
                throw ValidationException::withMessages(['verification' => 'Your wallet balance is insufficient. Fund your wallet before verifying.']);
            }
            $reference = (string) Str::ulid();
            $oldBalance = $owner->wallet_balance;
            $owner->wallet_balance -= $price;
            $owner->save();
            $charge = WalletTransaction::create([
                'reference' => $reference, 'request_key' => $requestKey, 'user_id' => $owner->id, 'actor_id' => $owner->id,
                'service' => $label, 'type' => 'service_debit', 'status' => 'completed', 'amount' => $price,
                'old_balance' => $oldBalance, 'new_balance' => $owner->wallet_balance, 'reason' => $label.' verification '.$reference,
            ]);

            return [$recordClass::create([
                'user_id' => $owner->id, 'wallet_transaction_id' => $charge->id, 'reference' => $reference,
                'request_key' => $requestKey, 'method' => $method,
                ...($method !== 'bvn' ? ['version' => $version] : []),
                ...($method === 'bvn' ? ['bvn' => $identifier] : ['nin' => $method === 'nin' ? $identifier : '', 'phone' => $method === 'phone' ? $identifier : null]),
                'price' => $price, 'status' => 'processing',
                'charge_errors' => $method !== 'bvn' && (bool) $pricing->charge_errors,
            ]), true];
        }, 3);
        if (! $created) {
            return $record;
        }

        $endpoint = match (true) {
            $method === 'bvn' => '/api/v1/verify',
            $version === 'v3' => '/api/v2/verify',
            $method === 'nin' && $version === 'v1' => '/api/v3/verify',
            default => '/api/v1/verify',
        };
        $providerType = $method === 'nin' && $version === 'v3' ? 'nin_basic' : $method;
        $chargeError = false;
        try {
            $response = Http::withToken($token)->asJson()->acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(30)
                ->post(rtrim($url, '/').$endpoint, ['idNumber' => $identifier, 'type' => $providerType, 'consent' => true]);
            $body = $response->json();
            $message = is_array($body) ? ($body['message'] ?? null) : null;
            if ($response->successful() && is_array($body) && ($body['status'] ?? null) === true && is_array($message)
                && ! array_is_list($message) && is_string($message['idNumber'] ?? null)
                && preg_match('/\A[0-9]{11}\z/', $message['idNumber']) === 1
                && ($method === 'phone' || $message['idNumber'] === $identifier)
                && is_string($message['firstName'] ?? null) && trim($message['firstName']) !== ''
                && is_string($message['lastName'] ?? null) && trim($message['lastName']) !== '') {
                $record->result = $message;
                $record->{$identifierField} = $message['idNumber'];
                $record->provider_reference = is_string($body['transID'] ?? null) ? Str::limit($body['transID'], 255, '') : null;
                $record->status = 'successful';
                $record->save();

                return $record;
            }
            $chargeError = $record->charge_errors && $this->isChargeableError($response, $body);
        } catch (ConnectionException) {
        }

        return DB::transaction(function () use ($record, $chargeError, $recordClass, $label): VerificationRecord {
            $owner = User::query()->lockForUpdate()->findOrFail($record->user_id);
            $current = $recordClass::query()->lockForUpdate()->findOrFail($record->id);
            if ($current->status !== 'processing') {
                return $current;
            }
            if ($chargeError) {
                $current->status = 'charged_error';
                $current->save();

                return $current;
            }
            $oldBalance = $owner->wallet_balance;
            $owner->wallet_balance += $current->price;
            $owner->save();
            WalletTransaction::create([
                'refund_of_id' => $current->wallet_transaction_id, 'reference' => (string) Str::ulid(), 'request_key' => (string) Str::uuid(),
                'user_id' => $owner->id, 'actor_id' => $owner->id, 'service' => $label, 'type' => 'service_refund', 'status' => 'completed',
                'amount' => $current->price, 'old_balance' => $oldBalance, 'new_balance' => $owner->wallet_balance,
                'reason' => 'Unsuccessful '.$label.' verification '.$current->reference,
            ]);
            $current->status = 'failed';
            $current->save();

            return $current;
        }, 3);
    }

    private function isChargeableError(Response $response, mixed $body): bool
    {
        if (! in_array($response->status(), [200, 201, 400, 404, 409, 422], true)
            || ! is_array($body) || ($body['status'] ?? null) !== false) {
            return false;
        }

        $parts = [];
        foreach (['message', 'error', 'code', 'errorCode', 'error_code', 'message.message', 'message.error', 'message.description'] as $key) {
            $value = data_get($body, $key);
            if (is_string($value) && trim($value) !== '') {
                $parts[] = $value;
            }
        }
        if ($parts === []) {
            return false;
        }
        $description = implode(' ', $parts);

        return preg_match('/server|internal[ _-]*error|unavail|not[ _-]*available|insuff|fund|balance|credit|quota|limit|timeout|timed[ _-]*out|temporar|maintenance|network|connect|upstream|gateway|provider[ _-]*(?:error|fail)|system[ _-]*(?:error|fail)|service[ _-]*(?:error|fail|down)|busy|auth|token|credential|permission|access[ _-]*denied|account[ _-]*(?:disabled|suspend)|endpoint|route[ _-]*not[ _-]*found|method[ _-]*not[ _-]*allowed/i', $description) !== 1;
    }
}
