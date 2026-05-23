<?php

namespace App\Models;

use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'name', 'key', 'tier', 'daily_limit', 'webhooks_enabled', 'satellite_limit', 'last_used_at', 'expires_at'])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory, SoftDeletes;

    public static function generate(): string
    {
        return 'dm_live_'.Str::random(32);
    }

    public static function tierDefaults(string $tier = 'free'): array
    {
        return match ($tier) {
            'starter' => ['daily_limit' => 10000,   'webhooks_enabled' => true,  'satellite_limit' => null],
            'pro' => ['daily_limit' => 100000,  'webhooks_enabled' => true,  'satellite_limit' => null],
            'enterprise' => ['daily_limit' => null,    'webhooks_enabled' => true,  'satellite_limit' => null],
            default => ['daily_limit' => 100,     'webhooks_enabled' => false, 'satellite_limit' => 5],
        };
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ApiUsage::class);
    }

    public function todayUsageCount(): int
    {
        return $this->usages()->whereDate('created_at', today())->count();
    }

    protected function casts(): array
    {
        return [
            'webhooks_enabled' => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
