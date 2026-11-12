<?php

namespace App;

use App\Models\AdminAudit;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletAdjustment
{
    public function apply(User $actor, int $userId, string $direction, string $amount, string $reason, string $requestKey): WalletTransaction
    {
        Gate::forUser($actor)->authorize('wallet.adjust');
        $reason = trim($reason);

        Validator::make(compact('direction', 'amount', 'reason', 'requestKey'), [
            'direction' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'regex:'.Money::INPUT_PATTERN],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'requestKey' => ['required', 'uuid'],
        ])->validate();

        $minor = Money::parse($amount);

        if ($minor < 1) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount greater than zero.']);
        }

        return DB::transaction(function () use ($actor, $userId, $direction, $minor, $reason, $requestKey): WalletTransaction {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $existing = WalletTransaction::query()->where('request_key', $requestKey)->first();

            if ($existing !== null) {
                if ($existing->user_id !== $userId || $existing->actor_id !== $actor->id || $existing->type !== $direction || $existing->amount !== $minor || $existing->reason !== $reason) {
                    throw ValidationException::withMessages(['amount' => 'This adjustment reference has already been used. Reload the page to start a new adjustment.']);
                }

                return $existing;
            }

            $oldBalance = $user->wallet_balance;
            $newBalance = $direction === 'credit' ? $oldBalance + $minor : $oldBalance - $minor;

            if ($newBalance < 0) {
                throw ValidationException::withMessages(['amount' => 'The debit exceeds the available wallet balance.']);
            }

            if ($newBalance > Money::MAX_MINOR) {
                throw ValidationException::withMessages(['amount' => 'The resulting wallet balance exceeds the supported maximum.']);
            }

            $user->wallet_balance = $newBalance;
            $user->save();

            $transaction = WalletTransaction::create([
                'reference' => (string) Str::ulid(),
                'request_key' => $requestKey,
                'user_id' => $user->id,
                'actor_id' => $actor->id,
                'service' => 'Wallet '.$direction,
                'type' => $direction,
                'status' => 'completed',
                'amount' => $minor,
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
                'reason' => $reason,
            ]);

            AdminAudit::create([
                'actor_id' => $actor->id, 'user_id' => $user->id, 'event' => 'wallet.'.$direction,
                'details' => ['reference' => $transaction->reference, 'amount' => $minor],
            ]);

            return $transaction;
        }, 3);
    }
}
