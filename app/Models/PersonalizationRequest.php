<?php

namespace App\Models;

use Database\Factories\PersonalizationRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'reference', 'tracking_id', 'tracking_hash', 'provider_id', 'provider_status', 'status', 'result', 'last_error', 'last_checked_at'])]
#[Hidden(['tracking_id', 'tracking_hash', 'result'])]
class PersonalizationRequest extends VerificationRecord
{
    /** @use HasFactory<PersonalizationRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['tracking_id' => 'encrypted', 'result' => 'encrypted:array', 'last_checked_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function field(string $key): string
    {
        return $key === 'trackingId' ? (parent::field('tracking_id') ?: $this->tracking_id) : parent::field($key);
    }

    public function statusLabel(): string
    {
        return $this->provider_status ?? match ($this->status) {
            'pending', 'processing', 'unconfirmed' => 'Pending',
            'in_progress' => 'In-Progress',
            'successful' => 'Successful',
            'failed' => 'Failed',
            default => 'Status unconfirmed',
        };
    }
}
