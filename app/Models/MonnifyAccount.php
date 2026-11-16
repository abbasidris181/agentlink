<?php

namespace App\Models;

use Database\Factories\MonnifyAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'mode', 'contract_code', 'reference', 'customer_email', 'customer_name', 'reservation_reference', 'status', 'accounts', 'provider_response', 'last_error'])]
#[Hidden(['customer_email', 'provider_response'])]
class MonnifyAccount extends Model
{
    /** @use HasFactory<MonnifyAccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['accounts' => 'array', 'provider_response' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MonnifyPayment::class);
    }
}
