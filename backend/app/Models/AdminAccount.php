<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_active', 'mfa_secret', 'last_login_at'])]
#[Hidden(['password'])]
class AdminAccount extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'admin_accounts';

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    protected function casts(): array
    {
        return [
            'password'      => 'hashed',
            'is_active'     => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }
}
