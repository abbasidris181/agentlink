<?php

namespace App;

use App\Models\AdminAudit;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletTransfer
{
    public function recipient(User $sender, string $identifier): User
    {
        Gate::forUser($sender)->authorize('wallet.transfer');
        $identifier = trim($identifier);
        Validator::make(['recipient' => $identifier], ['recipient' => ['required', 'string', 'max:255']])->validate();
        $query = User::query()->select(['id', 'name', 'is_active']);
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $query->where('email', mb_strtolower($identifier));
        } else {
            $phone = PhoneNumber::normalize($identifier);
            if ($phone === null) {
                throw ValidationException::withMessages(['recipient' => 'Enter a valid email or phone number.']);
            }
            $query->where('normalized_phone', $phone);
        }
        $matches = $query->limit(2)->get();
        if ($matches->count() !== 1 || ! $matches->first()->is_active) {
            throw ValidationException::withMessages(['recipient' => 'No unique active account matches. Try the recipient email instead.']);
        }
        $recipient = $matches->first();
        if ($recipient->id === $sender->id) {
            throw ValidationException::withMessages(['recipient' => 'Choose another user as recipient.']);
        }

        return $recipient;
    }

    public function send(User $sender, int $recipientId, string $amount, string $requestKey): WalletTransaction
    {
        Gate::forUser($sender)->authorize('wallet.transfer');
        Validator::make(compact('amount', 'requestKey'), ['amount' => ['required', 'regex:'.Money::INPUT_PATTERN], 'requestKey' => ['required', 'uuid']])->validate();
        $minor = Money::parse($amount);
        if ($minor < 1) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount greater than zero.']);
        }
        if ($sender->id === $recipientId) {
            throw ValidationException::withMessages(['recipient' => 'Choose another user as recipient.']);
        }
        try {
            return DB::transaction(function () use ($sender, $recipientId, $minor, $requestKey): WalletTransaction {
                $users = User::query()->whereIn('id', [$sender->id, $recipientId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $source = $users->get($sender->id);
                $destination = $users->get($recipientId);
                abort_unless($source !== null && AdminAccess::allows($source, 'wallet.transfer'), 403);
                if ($destination === null || ! $destination->is_active) {
                    throw ValidationException::withMessages(['recipient' => 'The recipient account is unavailable.']);
                }
                $existing = WalletTransaction::query()->where('request_key', $requestKey)->first();
                if ($existing !== null) {
                    if ($existing->user_id !== $source->id || $existing->counterparty_id !== $recipientId || $existing->type !== 'transfer_debit' || $existing->amount !== $minor) {
                        throw ValidationException::withMessages(['amount' => 'This transfer reference has already been used.']);
                    }

                    return $existing;
                }
                if ($source->wallet_balance < $minor) {
                    throw ValidationException::withMessages(['amount' => 'Insufficient wallet balance.']);
                }
                if ($destination->wallet_balance + $minor > Money::MAX_MINOR) {
                    throw ValidationException::withMessages(['amount' => 'The recipient balance would exceed the supported maximum.']);
                }
                $reference = (string) Str::ulid();
                $debit = null;
                foreach ([[$source, $destination, -$minor, 'transfer_debit'], [$destination, $source, $minor, 'transfer_credit']] as [$user, $counterparty, $change, $type]) {
                    $oldBalance = $user->wallet_balance;
                    $user->wallet_balance += $change;
                    $user->save();
                    $entry = WalletTransaction::create([
                        'reference' => (string) Str::ulid(), 'transfer_reference' => $reference,
                        'request_key' => $type === 'transfer_debit' ? $requestKey : (string) Str::uuid(),
                        'user_id' => $user->id, 'actor_id' => $source->id, 'counterparty_id' => $counterparty->id,
                        'service' => $type === 'transfer_debit' ? 'Wallet transfer sent' : 'Wallet transfer received',
                        'type' => $type, 'status' => 'completed', 'amount' => $minor,
                        'old_balance' => $oldBalance, 'new_balance' => $user->wallet_balance,
                        'reason' => 'Wallet transfer '.$reference,
                    ]);
                    if ($type === 'transfer_debit') {
                        $debit = $entry;
                    }
                }
                AdminAudit::create(['actor_id' => $source->id, 'user_id' => $destination->id, 'event' => 'wallet.transferred',
                    'details' => ['transfer_reference' => $reference, 'amount' => $minor, 'sender_id' => $source->id, 'recipient_id' => $destination->id]]);

                return $debit;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['amount' => 'This transfer reference has already been used. Review your transaction history.']);
        }
    }
}
