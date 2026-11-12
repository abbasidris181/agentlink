<?php

namespace App\Models;

use App\Casts\DecimalMoney;
use App\PhoneNumber;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'first_name', 'surname', 'phone', 'business', 'address', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['is_admin' => false, 'is_active' => true, 'wallet_balance' => 0, 'role' => 'users'];

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->isDirty('phone')) {
                $user->normalized_phone = PhoneNumber::normalize($user->phone);
            }
            if ($user->isDirty('role')) {
                $user->is_admin = $user->role === 'super_admin';
            }
        });
    }

    public function needsEmailVerification(): bool
    {
        return (bool) $this->requires_email_verification && ! $this->hasVerifiedEmail()
            && SiteSetting::enabled('email_verification_enabled');
    }

    public function sendEmailVerificationNotification(): void
    {
        if ($this->needsEmailVerification()) {
            $this->notify(new VerifyEmail);
        }
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'staff_permissions' => 'array',
            'requires_email_verification' => 'boolean',
            'is_active' => 'boolean',
            'wallet_balance' => DecimalMoney::class,
            'last_login_at' => 'datetime',
        ];
    }
}
