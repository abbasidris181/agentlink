<?php

namespace App\Models;

use App\Casts\DecimalMoney;
use Database\Factories\MonnifyPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['monnify_account_id', 'wallet_transaction_id', 'mode', 'transaction_reference', 'payment_reference', 'charge_basis_points', 'status', 'amount_paid', 'settlement_amount', 'credited_amount', 'fee_amount', 'event_data', 'verification_response', 'last_error', 'processing_started_at', 'credited_at'])]
#[Hidden(['event_data', 'verification_response'])]
class MonnifyPayment extends Model
{
    /** @use HasFactory<MonnifyPaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'charge_basis_points' => 'integer', 'event_data' => 'array', 'verification_response' => 'array',
            'amount_paid' => DecimalMoney::class, 'settlement_amount' => DecimalMoney::class, 'credited_amount' => DecimalMoney::class, 'fee_amount' => DecimalMoney::class,
            'processing_started_at' => 'immutable_datetime', 'credited_at' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MonnifyAccount::class, 'monnify_account_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
