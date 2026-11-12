<?php

namespace App\Models;

use App\Casts\DecimalMoney;
use Database\Factories\WalletTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['instant_ipe_request_id', 'refund_of_id', 'service_request_id', 'transfer_reference', 'counterparty_id', 'reference', 'request_key', 'user_id', 'actor_id', 'service', 'type', 'status', 'amount', 'old_balance', 'new_balance', 'reason'])]
class WalletTransaction extends Model
{
    /** @use HasFactory<WalletTransactionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['amount' => DecimalMoney::class, 'old_balance' => DecimalMoney::class, 'new_balance' => DecimalMoney::class];
    }

    public function refund(): HasOne
    {
        return $this->hasOne(self::class, 'refund_of_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instantIpeRequest(): BelongsTo
    {
        return $this->belongsTo(InstantIpeRequest::class);
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
