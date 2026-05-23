<?php

namespace App\Models;

use App\Observers\AdminAccountObserver;
use Database\Factories\AdminAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_active', 'mfa_secret', 'mfa_recovery_codes', 'last_login_at'])]
#[Hidden(['password', 'mfa_secret', 'mfa_recovery_codes'])]
class AdminAccount extends Authenticatable
{
    /** @use HasFactory<AdminAccountFactory> */
    use HasApiTokens, HasFactory;

    protected $table = 'admin_accounts';

    protected static function booted(): void
    {
        static::observe(AdminAccountObserver::class);
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /** Returns true when the admin has a TOTP secret configured. */
    public function hasMfa(): bool
    {
        return ! empty($this->mfa_secret);
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
        ];
    }
}
