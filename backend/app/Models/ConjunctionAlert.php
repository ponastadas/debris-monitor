<?php

namespace App\Models;

use Database\Factories\ConjunctionAlertFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'primary_norad_id', 'primary_name',
    'secondary_norad_id', 'secondary_name',
    'tca', 'miss_distance_km', 'probability', 'risk_score',
    'source', 'conjunction_event_id',
    'notified_at',
])]
class ConjunctionAlert extends Model
{
    /** @use HasFactory<ConjunctionAlertFactory> */
    use HasFactory;
    /** Alerts whose TCA is within the next $days days. */
    public function scopeUpcoming(Builder $query, int $days = 5): Builder
    {
        return $query
            ->where('tca', '>', now())
            ->where('tca', '<=', now()->addDays($days));
    }

    /** Alerts not yet notified. */
    public function scopeUnnotified(Builder $query): Builder
    {
        return $query->whereNull('notified_at');
    }

    /** Human-readable risk label. */
    public function riskLevel(): string
    {
        return match (true) {
            $this->risk_score >= 70 => 'HIGH',
            $this->risk_score >= 40 => 'MEDIUM',
            default                 => 'LOW',
        };
    }

    /** Hours until closest approach. */
    public function hoursUntilTca(): float
    {
        return now()->diffInMinutes($this->tca) / 60;
    }

    protected function casts(): array
    {
        return [
            'tca'          => 'datetime',
            'notified_at'  => 'datetime',
            'probability'  => 'float',
        ];
    }
}
