<?php

namespace App;

use App\Models\MonielinksAccount;
use App\Models\MonielinksPayment;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class MonielinksFundingService
{
    public function reserve(User $user): MonielinksAccount
    {
        [$account, $settings, $claimed] = DB::transaction(function () use ($user): array {
            SiteSetting::query()->where('key', 'monielinks')->lockForUpdate()->first();
            $settings = MonielinksSettings::get();
            MonielinksSettings::validate($settings);
            if (! $settings['enabled']) {
                throw ValidationException::withMessages(['funding' => 'New Monielinks accounts are temporarily unavailable.']);
            }
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($owner->is_active && ! $owner->needsEmailVerification(), 403);
            $account = MonielinksAccount::query()->where('user_id', $owner->id)->first();
            if ($account && $account->status !== 'failed') {
                return [$account, $settings, false];
            }
            $details = [
                'customer_email' => mb_strtolower(trim($owner->email)),
                'first_name' => trim($owner->first_name ?? ''), 'last_name' => trim($owner->surname ?? ''),
            ];
            Validator::make($details, [
                'customer_email' => ['required', 'email:rfc', 'max:255'],
                'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:100'],
            ], ['first_name.required' => 'Add your first name to your profile before creating an account.', 'last_name.required' => 'Add your surname to your profile before creating an account.'])->validate();
            if (MonielinksAccount::query()->where('customer_email', $details['customer_email'])->when($account, fn ($query) => $query->whereKeyNot($account->id))->exists()) {
                throw ValidationException::withMessages(['funding' => 'This email is already associated with a virtual account. Contact support.']);
            }
            $values = [...$details, 'client_id' => $settings['client_id'], 'status' => 'creating', 'last_error' => null];
            if ($account) {
                $account->update($values);
            } else {
                $account = MonielinksAccount::query()->create(['user_id' => $owner->id, ...$values]);
            }

            return [$account, $settings, true];
        });
        if (! $claimed) {
            return $account;
        }
        $client = new MonielinksClient($settings);
        try {
            $token = $client->authenticate();
        } catch (Throwable) {
            $account->update(['status' => 'failed', 'last_error' => 'Monielinks authentication could not be confirmed. Please try again later.']);

            return $account->fresh();
        }
        try {
            $response = $client->createAccount($token, [
                'email' => $account->customer_email, 'firstName' => $account->first_name,
                'lastName' => $account->last_name, 'bank' => 'PALMPAY',
            ]);

            return $this->applyAccountResponse($account, $response);
        } catch (Throwable) {
            $account->update(['status' => 'uncertain', 'last_error' => 'The account request outcome is uncertain. Contact support before trying again.']);

            return $account->fresh();
        }
    }

    private function applyAccountResponse(MonielinksAccount $account, Response $response): MonielinksAccount
    {
        $body = $response->json();
        $details = is_array($body) ? ($body['data']['account'] ?? null) : null;
        $valid = $response->successful() && is_array($body) && ($body['type'] ?? null) === 'success' && is_array($details);
        if ($valid) {
            $validator = Validator::make($details, [
                'account_number' => ['required', 'string', 'regex:/\A[0-9]{10}\z/'],
                'account_name' => ['required', 'string', 'max:200'],
                'bank' => ['required', 'string', 'max:100'],
                'email' => ['required', 'string', 'email:rfc', 'max:255'],
                'reference' => ['required', 'string', 'max:160', 'not_regex:/[\r\n]/'],
            ]);
            $valid = ! $validator->fails()
                && mb_strtolower(trim($details['email'])) === $account->customer_email
                && in_array(mb_strtoupper(trim($details['bank'])), ['PALMPAY', 'PALMPAY BANK'], true);
        }
        if (! $valid) {
            $rejected = in_array($response->status(), [400, 401, 403, 422], true) && is_array($body) && ($body['type'] ?? null) === 'error';
            $account->update([
                'status' => $rejected ? 'failed' : 'uncertain', 'provider_response' => is_array($body) ? $body : null,
                'last_error' => $rejected ? 'Monielinks rejected the account request. Contact support or try again later.' : 'Account creation could not be confirmed. Contact support before trying again.',
            ]);
        } else {
            $account->update([
                'status' => 'active', 'account_number' => $details['account_number'], 'account_name' => $details['account_name'],
                'bank' => $details['bank'], 'reference' => $details['reference'], 'provider_response' => $body, 'last_error' => null,
            ]);
        }

        return $account->fresh();
    }

    /** @param array<string, mixed> $event */
    public function receive(array $event, bool $signatureVerified = false): ?MonielinksPayment
    {
        if (($event['event'] ?? null) !== 'payment.success') {
            return null;
        }
        $data = $event['data'] ?? null;
        if (! is_array($data)) {
            throw ValidationException::withMessages(['payment' => 'Missing payment details.']);
        }
        $accountNumber = $data['accountnumber'] ?? $data['account_number'] ?? null;
        if (isset($data['accountnumber'], $data['account_number']) && $data['accountnumber'] !== $data['account_number']) {
            throw ValidationException::withMessages(['payment' => 'Conflicting account numbers.']);
        }
        Validator::make([...$data, 'accountnumber' => $accountNumber], [
            'trx_id' => ['required', 'string', 'max:160', 'not_regex:/[\r\n]/'],
            'reference' => ['required', 'string', 'max:160', 'not_regex:/[\r\n]/'],
            'amount' => ['required', 'string', 'regex:'.Money::INPUT_PATTERN],
            'currency' => ['required', 'in:NGN'], 'status' => ['required', 'in:successful'],
            'accountnumber' => ['required', 'string', 'regex:/\A[0-9]{10}\z/'],
        ])->validate();

        $amount = Money::parse($data['amount']);
        if ($amount <= 0 || $amount > Money::MAX_MINOR) {
            throw ValidationException::withMessages(['payment' => 'Invalid payment amount.']);
        }

        return DB::transaction(function () use ($data, $event, $amount, $signatureVerified, $accountNumber): ?MonielinksPayment {
            $settings = MonielinksSettings::get();
            $account = MonielinksAccount::query()->where('account_number', $accountNumber)
                ->where('client_id', $settings['client_id'])->where('status', 'active')->first();
            if (! $account) {
                return null;
            }
            $values = [
                'monielinks_account_id' => $account->id,
                'reference' => $data['reference'], 'amount_paid' => $amount, 'event_data' => $event,
                'charge_basis_points' => Money::parse($settings['funding_charge']),
                'status' => $signatureVerified ? 'review' : 'unverified',
                'last_error' => $signatureVerified ? 'Awaiting provider payment verification.' : 'Unsigned notification. Payment must be confirmed with the provider before wallet credit.',
            ];
            $payment = MonielinksPayment::query()->firstOrCreate(
                ['transaction_reference' => $data['trx_id']],
                $values,
            );
            $payment = MonielinksPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($signatureVerified && $payment->status === 'unverified') {
                $payment->update($values);

                return $payment;
            }
            if ($payment->monielinks_account_id !== $account->id || $payment->reference !== $data['reference'] || $payment->amount_paid !== $amount) {
                throw ValidationException::withMessages(['payment' => 'Conflicting payment details.']);
            }

            return $payment;
        });
    }
}
