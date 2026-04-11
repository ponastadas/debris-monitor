<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'norad_id', 'name', 'tle_line1', 'tle_line2', 'tle_fetched_at'])]
class WatchedSatellite extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasFreshTle(): bool
    {
        return $this->tle_fetched_at !== null
            && $this->tle_fetched_at->diffInHours(now()) < 6;
    }

    protected function casts(): array
    {
        return [
            'tle_fetched_at' => 'datetime',
        ];
    }
}
