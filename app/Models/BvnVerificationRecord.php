<?php

namespace App\Models;

use App\Casts\DecimalMoney;
use Database\Factories\BvnVerificationRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'wallet_transaction_id', 'reference', 'request_key', 'method', 'bvn', 'price', 'status', 'result', 'provider_reference', 'charge_errors'])]
#[Hidden(['bvn', 'result'])]
class BvnVerificationRecord extends VerificationRecord
{
    /** @use HasFactory<BvnVerificationRecordFactory> */
    use HasFactory;

    public const SLIPS = [
        'slip' => ['label' => 'BVN slip', 'preview' => 'bvnslip.png', 'sample' => 'sample-bvnslip.png'],
        'plastic' => ['label' => 'Plastic BVN', 'preview' => 'bvn.jpg', 'sample' => 'sample-bvn.jpg'],
    ];

    protected function casts(): array
    {
        return ['bvn' => 'encrypted', 'result' => 'encrypted:array', 'price' => DecimalMoney::class, 'charge_errors' => 'boolean'];
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
