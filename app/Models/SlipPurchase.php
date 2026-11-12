<?php

namespace App\Models;

use App\Casts\DecimalMoney;
use Database\Factories\SlipPurchaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'nin_verification_record_id', 'bvn_verification_record_id', 'personalization_request_id', 'wallet_transaction_id', 'slip', 'price'])]
class SlipPurchase extends Model
{
    /** @use HasFactory<SlipPurchaseFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['price' => DecimalMoney::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
