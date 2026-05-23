<?php

namespace App\Models;

use Database\Factories\ConjunctionEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'cdm_id', 'created_at_cdm', 'tca', 'min_range_km', 'probability',
    'emergency_reportable', 'sat1_norad_id', 'sat1_name',
    'sat2_norad_id', 'sat2_name', 'source', 'fetched_at',
])]
class ConjunctionEvent extends Model
{
    /** @use HasFactory<ConjunctionEventFactory> */
    use HasFactory;

    /**
     * Events with TCA in the future (or within 1 day past — useful for just-missed events).
     * Window: past 24 h to 7 days out.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('tca', '>', now()->subDay())
            ->where('tca', '<', now()->addDays(7));
    }

    /** Events involving a given NORAD ID as either the primary or secondary object. */
    public function scopeForObject(Builder $query, string $noradId): Builder
    {
        return $query->where(fn ($q) => $q
            ->where('sat1_norad_id', $noradId)
            ->orWhere('sat2_norad_id', $noradId));
    }

    /**
     * Derive a 0–100 risk score from the CDM fields.
     * PC is the primary signal when available; miss distance as a secondary floor.
     */
    public function riskScore(): int
    {
        $fromDist = max(0, (int) round(100 * max(0, 1 - $this->min_range_km / 10.0)));

        if ($this->probability !== null && $this->probability > 0) {
            $fromPc = match (true) {
                $this->probability >= 0.001 => 90,
                $this->probability >= 0.0001 => 75,
                $this->probability >= 0.00001 => 55,
                $this->probability >= 0.000001 => 35,
                default => 15,
            };

            return max($fromDist, $fromPc);
        }

        return $fromDist;
    }

    public function riskLevel(): string
    {
        return match (true) {
            $this->riskScore() >= 70 => 'HIGH',
            $this->riskScore() >= 40 => 'MEDIUM',
            default => 'LOW',
        };
    }

    protected function casts(): array
    {
        return [
            'tca' => 'datetime',
            'created_at_cdm' => 'datetime',
            'fetched_at' => 'datetime',
            'probability' => 'double',
            'min_range_km' => 'float',
            'emergency_reportable' => 'boolean',
        ];
    }
}
