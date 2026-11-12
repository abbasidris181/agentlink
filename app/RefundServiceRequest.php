<?php

namespace App;

use App\Models\AdminAudit;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RefundServiceRequest
{
    public function refund(User $actor, int $requestId): WalletTransaction
    {
        Gate::forUser($actor)->authorize('requests.refund');
        $request = ServiceRequest::query()->findOrFail($requestId);

        return DB::transaction(function () use ($actor, $requestId, $request): WalletTransaction {
            $owner = User::query()->lockForUpdate()->findOrFail($request->user_id);
            $record = ServiceRequest::query()->lockForUpdate()->findOrFail($requestId);
            Gate::forUser(User::query()->findOrFail($actor->id))->authorize('requests.refund');
            $charge = $record->walletTransaction()->lockForUpdate()->first();
            if ($charge === null || $charge->type !== 'service_debit' || $charge->status !== 'completed' || $charge->user_id !== $owner->id || $charge->amount < 1) {
                throw ValidationException::withMessages(['refund' => 'This request has no refundable payment.']);
            }
            $existing = $charge->refund()->first();
            if ($existing !== null) {
                return $existing;
            }
            $oldBalance = $owner->wallet_balance;
            $newBalance = $oldBalance + $charge->amount;
            if ($newBalance > Money::MAX_MINOR) {
                throw ValidationException::withMessages(['refund' => 'The refund would exceed the supported wallet balance.']);
            }
            $owner->wallet_balance = $newBalance;
            $owner->save();
            $refund = WalletTransaction::create([
                'refund_of_id' => $charge->id,
                'reference' => (string) Str::ulid(), 'request_key' => (string) Str::uuid(),
                'user_id' => $owner->id, 'actor_id' => $actor->id,
                'service' => $record->serviceLabel().' refund', 'type' => 'credit', 'status' => 'completed',
                'amount' => $charge->amount, 'old_balance' => $oldBalance, 'new_balance' => $newBalance,
                'reason' => 'Refund for service request '.$record->reference,
            ]);
            $previousStatus = $record->status;
            $record->status = 'refunded';
            $record->reply ??= 'Your payment has been refunded to your wallet.';
            $record->handled_by = $actor->id;
            $record->handled_at = now();
            $record->revision++;
            $record->save();
            AdminAudit::create(['actor_id' => $actor->id, 'user_id' => $owner->id, 'event' => 'service_request.refunded', 'details' => ['reference' => $record->reference, 'refund_reference' => $refund->reference, 'amount' => $charge->amount, 'old_status' => $previousStatus]]);

            return $refund;
        }, 3);
    }
}
