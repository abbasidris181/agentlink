<?php

namespace App\Models;

use Database\Factories\InstantIpeSubmissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'request_key', 'batch_id', 'mode', 'input_hash'])]
#[Hidden(['request_key', 'input_hash'])]
class InstantIpeSubmission extends Model
{
    /** @use HasFactory<InstantIpeSubmissionFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(InstantIpeRequest::class);
    }
}
