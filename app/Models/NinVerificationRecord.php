<?php

namespace App\Models;

use App\Casts\DecimalMoney;
use Database\Factories\NinVerificationRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'wallet_transaction_id', 'reference', 'request_key', 'version', 'method', 'nin', 'phone', 'price', 'status', 'result', 'provider_reference', 'charge_errors'])]
#[Hidden(['nin', 'phone', 'result'])]
class NinVerificationRecord extends VerificationRecord
{
    /** @use HasFactory<NinVerificationRecordFactory> */
    use HasFactory;

    protected $attributes = ['version' => 'v1'];

    public const SLIPS = [
        'regular' => ['label' => 'Regular slip', 'preview' => 'regular.png', 'sample' => 'sample-regular.png'],
        'premium' => ['label' => 'Premium slip', 'preview' => 'premium.jpg', 'sample' => 'sample-premium.png'],
        'standard' => ['label' => 'Standard slip', 'preview' => 'standard.jpg', 'sample' => 'sample-standard.png'],
        'digital' => ['label' => 'Basic slip', 'preview' => 'basic.jpg', 'sample' => 'sample-ninDig.jpg'],
    ];

    protected function casts(): array
    {
        return ['nin' => 'encrypted', 'phone' => 'encrypted', 'result' => 'encrypted:array', 'price' => DecimalMoney::class, 'charge_errors' => 'boolean'];
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
