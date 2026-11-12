<?php

namespace App\Models;

use App\Casts\DecimalMoney;
use Database\Factories\InstantIpeRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['instant_ipe_submission_id', 'user_id', 'reference', 'tracking_id', 'active_tracking_id', 'price', 'status', 'provider_status', 'reply', 'nin', 'last_error', 'dispatch_started_at', 'submitted_at', 'last_checked_at', 'next_check_at', 'check_lease_until', 'check_token', 'refunded_at'])]
#[Hidden(['reply', 'nin', 'check_token'])]
class InstantIpeRequest extends Model
{
    /** @use HasFactory<InstantIpeRequestFactory> */
    use HasFactory;

    public const STATUSES = ['queued' => 'Queued', 'submitting' => 'Submitting', 'pending' => 'Pending', 'successful' => 'Successful', 'failed' => 'Failed', 'unconfirmed' => 'Needs confirmation'];

    protected function casts(): array
    {
        return ['price' => DecimalMoney::class, 'admin_replied_at' => 'datetime', 'dispatch_started_at' => 'datetime', 'submitted_at' => 'datetime', 'last_checked_at' => 'datetime', 'next_check_at' => 'datetime', 'check_lease_until' => 'datetime', 'refunded_at' => 'datetime'];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(InstantIpeSubmission::class, 'instant_ipe_submission_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function walletTransaction(): HasOne
    {
        return $this->hasOne(WalletTransaction::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function canCheck(): bool
    {
        return $this->dispatch_started_at !== null && $this->status !== 'successful'
            && ($this->status !== 'failed' || $this->refunded_at === null)
            && ($this->status !== 'submitting' || $this->dispatch_started_at->lte(now()->subMinute()));
    }
}
