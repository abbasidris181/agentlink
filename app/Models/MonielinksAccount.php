<?php

namespace App\Models;

use Database\Factories\MonielinksAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'client_id', 'customer_email', 'first_name', 'last_name', 'status', 'account_number', 'account_name', 'bank', 'reference', 'provider_response', 'last_error'])]
#[Hidden(['client_id', 'customer_email', 'provider_response'])]
class MonielinksAccount extends Model
{
    /** @use HasFactory<MonielinksAccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['provider_response' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MonielinksPayment::class);
    }
}
