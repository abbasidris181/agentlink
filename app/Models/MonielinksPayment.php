<?php

namespace App\Models;

use App\Casts\DecimalMoney;
use Database\Factories\MonielinksPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['monielinks_account_id', 'wallet_transaction_id', 'transaction_reference', 'reference', 'charge_basis_points', 'status', 'amount_paid', 'credited_amount', 'fee_amount', 'event_data', 'last_error', 'credited_at'])]
#[Hidden(['event_data'])]
class MonielinksPayment extends Model
{
    /** @use HasFactory<MonielinksPaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'charge_basis_points' => 'integer', 'event_data' => 'array', 'amount_paid' => DecimalMoney::class,
            'credited_amount' => DecimalMoney::class, 'fee_amount' => DecimalMoney::class, 'credited_at' => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MonielinksAccount::class, 'monielinks_account_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
