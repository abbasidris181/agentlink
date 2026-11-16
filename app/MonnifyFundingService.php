<?php

namespace App;

use App\Jobs\VerifyMonnifyPayment;
use App\Models\MonnifyAccount;
use App\Models\MonnifyPayment;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class MonnifyFundingService
{
    public function reserve(User $user, string $bvn, string $nin, bool $consent): MonnifyAccount
    {
        Validator::make(compact('bvn', 'nin', 'consent'), [
            'bvn' => ['required_without:nin', 'nullable', 'string', 'regex:/\A[0-9]{11}\z/'],
            'nin' => ['required_without:bvn', 'nullable', 'string', 'regex:/\A[0-9]{11}\z/'],
            'consent' => ['accepted'],
        ])->validate();
        [$account, $claimed, $settings] = DB::transaction(function () use ($user): array {
            SiteSetting::query()->where('key', 'monnify')->lockForUpdate()->first();
            $settings = MonnifySettings::get();
            MonnifySettings::validate($settings);
            if (! $settings['enabled']) {
                throw ValidationException::withMessages(['funding' => 'Reserved account creation is temporarily unavailable.']);
            }
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->authorize($owner);
            Validator::make(['email' => $owner->email, 'name' => $owner->name], ['email' => ['required', 'email', 'max:255'], 'name' => ['required', 'string', 'max:200']])->validate();
            $account = MonnifyAccount::query()->where('user_id', $owner->id)->where('mode', $settings['mode'])->where('contract_code', $settings['contract_code'])->first();
            if ($account && $account->status !== 'failed') {
                return [$account, false, $settings];
            }
            $values = ['customer_email' => $owner->email, 'customer_name' => $owner->name, 'status' => 'creating', 'last_error' => null];
            if ($account) {
                $account->update($values);
            } else {
                $account = MonnifyAccount::query()->create([
                    ...$values, 'user_id' => $owner->id, 'mode' => $settings['mode'], 'contract_code' => $settings['contract_code'], 'reference' => 'WALLET-'.Str::ulid(),
                ]);
            }

            return [$account, true, $settings];
        });
        if (! $claimed) {
            return $account;
        }
        try {
            $response = (new MonnifyClient($settings))->request('reserve-account', 'POST', '/api/v2/bank-transfer/reserved-accounts', [
                'accountReference' => $account->reference, 'accountName' => $account->customer_name,
                'currencyCode' => 'NGN', 'contractCode' => $account->contract_code,
                'customerEmail' => $account->customer_email, 'customerName' => $account->customer_name,
                'getAllAvailableBanks' => true,
                ...($bvn !== '' ? ['bvn' => $bvn] : []), ...($nin !== '' ? ['nin' => $nin] : []),
            ]);

            return $this->applyAccountResponse($account, $response);
        } catch (Throwable) {
            MonnifyAccount::query()->whereKey($account->id)->where('status', '!=', 'active')->update(['status' => 'uncertain', 'last_error' => 'Account creation could not be confirmed. Check account status before trying again.']);

            return $account->refresh();
        }
    }

    public function refreshAccount(User $user): ?MonnifyAccount
    {
        $this->authorize($user->fresh());
        $settings = MonnifySettings::get();
        MonnifySettings::validate($settings);
        $account = MonnifyAccount::query()->where('user_id', $user->id)->where('mode', $settings['mode'])->where('contract_code', $settings['contract_code'])->first();
        if (! $account) {
            return null;
        }
        try {
            $response = (new MonnifyClient($settings))->request('get-reserved-account', 'GET', '/api/v2/bank-transfer/reserved-accounts/'.rawurlencode($account->reference));

            return $this->applyAccountResponse($account, $response, true);
        } catch (Throwable) {
            MonnifyAccount::query()->whereKey($account->id)->where('status', '!=', 'active')->update(['status' => 'uncertain', 'last_error' => 'The provider status is unconfirmed. Check again shortly.']);

            return $account->refresh();
        }
    }

    private function applyAccountResponse(MonnifyAccount $account, Response $response, bool $checking = false): MonnifyAccount
    {
        $body = MonnifyClient::body($response);
        $valid = $body && ($body['accountReference'] ?? null) === $account->reference
            && ($body['contractCode'] ?? null) === $account->contract_code && ($body['currencyCode'] ?? null) === 'NGN'
            && ($body['collectionChannel'] ?? null) === 'RESERVED_ACCOUNT' && ($body['reservedAccountType'] ?? null) === 'GENERAL'
            && ($body['status'] ?? null) === 'ACTIVE' && is_string($body['reservationReference'] ?? null)
            && strlen($body['reservationReference']) <= 100 && $body['reservationReference'] !== ''
            && strcasecmp((string) ($body['customerEmail'] ?? ''), $account->customer_email) === 0
            && is_array($body['accounts'] ?? null) && array_is_list($body['accounts']) && count($body['accounts']) > 0;
        $banks = [];
        if ($valid) {
            foreach ($body['accounts'] as $bank) {
                $errors = Validator::make(is_array($bank) ? $bank : [], [
                    'bankCode' => ['required', 'string', 'regex:/\A[0-9]{3,10}\z/'],
                    'bankName' => ['required', 'string', 'max:200'], 'accountNumber' => ['required', 'string', 'regex:/\A[0-9]{10}\z/'],
                    'accountName' => ['nullable', 'string', 'max:255'],
                ])->fails();
                if ($errors) {
                    $valid = false;
                    break;
                }
                $banks[] = ['bankCode' => $bank['bankCode'], 'bankName' => $bank['bankName'], 'accountNumber' => $bank['accountNumber'], 'accountName' => $bank['accountName'] ?? $body['accountName'] ?? $account->customer_name];
            }
        }

        return DB::transaction(function () use ($account, $response, $body, $banks, $valid, $checking): MonnifyAccount {
            $current = MonnifyAccount::query()->lockForUpdate()->findOrFail($account->id);
            if ($valid) {
                $current->update(['status' => 'active', 'accounts' => $banks, 'reservation_reference' => $body['reservationReference'], 'provider_response' => $response->json(), 'last_error' => null]);
            } elseif ($current->status !== 'active') {
                $rejected = (in_array($response->status(), [400, 401, 403, 422], true) || ($checking && $response->status() === 404)) && $response->json('requestSuccessful') === false;
                $current->update([
                    'status' => $rejected ? 'failed' : 'uncertain', 'provider_response' => is_array($response->json()) ? $response->json() : null,
                    'last_error' => $rejected ? 'Monnify could not create the account. Check your identity details or contact support.' : 'Account creation could not be confirmed. Check account status before trying again.',
                ]);
            }

            return $current;
        });
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $settings
     */
    public function receive(array $event, array $settings): ?MonnifyPayment
    {
        if (($event['eventType'] ?? null) !== 'SUCCESSFUL_TRANSACTION' || $settings['mode'] !== 'live') {
            return null;
        }
        $data = is_array($event['eventData'] ?? null) ? $event['eventData'] : [];
        if (($data['product']['type'] ?? null) !== 'RESERVED_ACCOUNT' || ($data['paymentStatus'] ?? null) !== 'PAID'
            || ($data['paymentMethod'] ?? null) !== 'ACCOUNT_TRANSFER' || ($data['currency'] ?? null) !== 'NGN') {
            return null;
        }
        Validator::make($data, [
            'transactionReference' => ['required', 'string', 'max:160', 'not_regex:/[\r\n]/'],
            'paymentReference' => ['required', 'string', 'max:160', 'not_regex:/[\r\n]/'],
            'product.reference' => ['required', 'string', 'max:64'],
            'destinationAccountInformation.accountNumber' => ['required', 'string', 'regex:/\A[0-9]{10}\z/'],
        ])->validate();
        $this->amount($data['amountPaid'] ?? null);
        $this->amount($data['settlementAmount'] ?? null);
        $account = MonnifyAccount::query()->where('reference', $data['product']['reference'])->where('mode', 'live')->where('contract_code', $settings['contract_code'])->where('status', 'active')->first();
        if (! $account) {
            return null;
        }
        if (! $this->destinationMatches($account, $data['destinationAccountInformation'])) {
            throw ValidationException::withMessages(['payment' => 'The destination account does not match.']);
        }
        $queueConnection = config('queue.connections.database.connection');
        if (config('queue.connections.database.driver') !== 'database' || ($queueConnection !== null && $queueConnection !== DB::getDefaultConnection())) {
            throw new RuntimeException('Monnify requires the database queue on the application database.');
        }

        return DB::transaction(function () use ($account, $settings, $data): MonnifyPayment {
            $payment = MonnifyPayment::query()->firstOrCreate(['mode' => 'live', 'transaction_reference' => $data['transactionReference']], [
                'monnify_account_id' => $account->id, 'payment_reference' => $data['paymentReference'],
                'charge_basis_points' => Money::parse($settings['funding_charge']), 'event_data' => $data, 'status' => 'pending',
            ]);
            if ($payment->monnify_account_id !== $account->id || $payment->payment_reference !== $data['paymentReference']) {
                throw ValidationException::withMessages(['payment' => 'The transaction reference has already been received for another payment.']);
            }
            if ($payment->wasRecentlyCreated) {
                VerifyMonnifyPayment::dispatch($payment->id)->beforeCommit();
            }

            return $payment;
        }, 3);
    }

    public function verifyPayment(int $paymentId): void
    {
        $payment = DB::transaction(function () use ($paymentId): ?MonnifyPayment {
            $payment = MonnifyPayment::query()->lockForUpdate()->findOrFail($paymentId);
            if (in_array($payment->status, ['credited', 'ignored'], true)
                || ($payment->status === 'processing' && $payment->processing_started_at?->gt(now()->subSeconds(60)))) {
                return null;
            }
            $payment->update(['status' => 'processing', 'processing_started_at' => now(), 'last_error' => null]);

            return $payment;
        });
        if (! $payment) {
            return;
        }
        $account = $payment->account;
        if ($payment->mode !== 'live' || $account->mode !== 'live') {
            $payment->update(['status' => 'ignored', 'last_error' => 'Sandbox payments do not credit wallets.']);

            return;
        }
        try {
            $settings = MonnifySettings::get();
            MonnifySettings::validate($settings);
            if ($settings['mode'] !== 'live' || $settings['contract_code'] !== $account->contract_code) {
                throw new RuntimeException('The payment belongs to a different Monnify contract.');
            }
            $response = (new MonnifyClient($settings))->request('verify-payment', 'GET', '/api/v2/merchant/transactions/query', ['transactionReference' => $payment->transaction_reference]);
            $body = MonnifyClient::body($response);
            if (! $body || in_array($body['paymentStatus'] ?? null, ['PENDING', 'PROCESSING'], true)) {
                throw new RuntimeException('Monnify verification is not available yet.');
            }
            $payment->verification_response = $response->json();
            $payment->save();
            $event = $payment->event_data;
            $amount = $this->amount($body['amountPaid'] ?? null);
            $settlement = $this->amount($body['settlementAmount'] ?? null);
            if (($body['transactionReference'] ?? null) !== $payment->transaction_reference
                || ($body['paymentReference'] ?? null) !== $payment->payment_reference
                || ($body['paymentStatus'] ?? null) !== 'PAID' || ($body['currency'] ?? null) !== 'NGN'
                || ($body['paymentMethod'] ?? null) !== 'ACCOUNT_TRANSFER'
                || ($body['product']['type'] ?? null) !== 'RESERVED_ACCOUNT' || ($body['product']['reference'] ?? null) !== $account->reference
                || $amount <= 0 || $settlement > $amount
                || $amount !== $this->amount($event['amountPaid'] ?? null) || $settlement !== $this->amount($event['settlementAmount'] ?? null)) {
                MonnifyPayment::query()->whereKey($payment->id)->whereNull('wallet_transaction_id')->where('status', '!=', 'credited')
                    ->update(['status' => 'review', 'last_error' => 'The verified payment details do not match the notification. Contact support.']);

                return;
            }
            $fee = intdiv($amount * $payment->charge_basis_points + 5000, 10000);
            $credit = $amount - $fee;
            DB::transaction(function () use ($payment, $account, $amount, $settlement, $fee, $credit): void {
                $owner = User::query()->lockForUpdate()->findOrFail($account->user_id);
                $current = MonnifyPayment::query()->lockForUpdate()->findOrFail($payment->id);
                if ($current->status === 'credited' || $current->wallet_transaction_id !== null) {
                    return;
                }
                if ($credit < 0 || $owner->wallet_balance > Money::MAX_MINOR - $credit) {
                    $current->update(['status' => 'review', 'last_error' => 'The wallet limit prevents this credit. Contact support.']);

                    return;
                }
                $oldBalance = $owner->wallet_balance;
                $owner->wallet_balance += $credit;
                $owner->save();
                $transaction = WalletTransaction::query()->create([
                    'user_id' => $owner->id, 'actor_id' => $owner->id, 'reference' => (string) Str::ulid(), 'request_key' => (string) Str::uuid(),
                    'service' => 'Monnify wallet funding', 'type' => 'credit', 'status' => 'completed',
                    'amount' => $credit, 'old_balance' => $oldBalance, 'new_balance' => $owner->wallet_balance,
                    'reason' => 'Monnify transfer '.$current->transaction_reference,
                ]);
                $current->update([
                    'status' => 'credited', 'amount_paid' => $amount, 'settlement_amount' => $settlement, 'fee_amount' => $fee,
                    'credited_amount' => $credit, 'wallet_transaction_id' => $transaction->id, 'credited_at' => now(), 'last_error' => null,
                ]);
            }, 3);
        } catch (Throwable) {
            MonnifyPayment::query()->whereKey($payment->id)->whereNotIn('status', ['credited', 'ignored'])->update([
                'status' => 'pending', 'last_error' => 'Provider verification is pending. Your wallet will be credited only after confirmation.',
            ]);
            throw new RuntimeException('Monnify payment verification could not be completed.');
        }
    }

    /** @param array<string, mixed> $destination */
    private function destinationMatches(MonnifyAccount $account, array $destination): bool
    {
        foreach ($account->accounts ?? [] as $bank) {
            if ($bank['accountNumber'] === ($destination['accountNumber'] ?? null)
                && (! isset($destination['bankCode']) || $destination['bankCode'] === $bank['bankCode'])) {
                return true;
            }
        }

        return false;
    }

    private function amount(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw ValidationException::withMessages(['payment' => 'Invalid payment amount.']);
        }
        try {
            $amount = Money::fromDecimal((string) $value);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['payment' => 'Invalid payment amount.']);
        }
        if ($amount > Money::MAX_MINOR) {
            throw ValidationException::withMessages(['payment' => 'Payment amount exceeds the supported limit.']);
        }

        return $amount;
    }

    private function authorize(User $user): void
    {
        abort_unless($user->is_active && ! $user->needsEmailVerification(), 403);
    }
}
