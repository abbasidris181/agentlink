<?php

namespace App\Models;

use Database\Factories\AdminNotificationSendFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'channel', 'status', 'payload', 'recipients', 'confirmed_at'])]
#[Hidden(['payload', 'recipients'])]
class AdminNotificationSend extends Model
{
    /** @use HasFactory<AdminNotificationSendFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['payload' => 'array', 'recipients' => 'array', 'confirmed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{accepted: int, failed: int, uncertain: int, not_attempted: int, total: int} */
    public function totals(): array
    {
        $counts = ['accepted' => 0, 'failed' => 0, 'uncertain' => 0, 'not_attempted' => 0, 'total' => count($this->recipients)];
        foreach ($this->recipients as $recipient) {
            $key = match ($recipient['status']) {
                'accepted', 'failed', 'uncertain' => $recipient['status'],
                'sending' => 'uncertain',
                default => 'not_attempted',
            };
            $counts[$key]++;
        }

        return $counts;
    }
}
