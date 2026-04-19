<?php

namespace App\Models;

use Database\Factories\SatelliteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'norad_id', 'name', 'object_type', 'international_designator',
    'country_code', 'launch_date', 'decay_date', 'is_active',
    'catalog_source', 'last_seen_at',
])]
class Satellite extends Model
{
    /** @use HasFactory<SatelliteFactory> */
    use HasFactory;

    public function tleRecords(): HasMany
    {
        return $this->hasMany(TleRecord::class);
    }

    /** The most recently fetched current TLE for this satellite. */
    public function currentTle(): HasOne
    {
        return $this->hasOne(TleRecord::class)->ofMany(
            ['fetched_at' => 'max'],
            fn ($q) => $q->where('is_current', true)
        );
    }

    protected function casts(): array
    {
        return [
            'launch_date'  => 'date',
            'decay_date'   => 'date',
            'last_seen_at' => 'datetime',
            'is_active'    => 'boolean',
        ];
    }
}
