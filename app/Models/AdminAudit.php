<?php

namespace App\Models;

use Database\Factories\AdminAuditFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_id', 'user_id', 'event', 'details'])]
class AdminAudit extends Model
{
    /** @use HasFactory<AdminAuditFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
