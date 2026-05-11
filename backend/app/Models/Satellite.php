<?php

namespace App\Models;

use Carbon\Carbon;
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

    /**
     * Replace the current TLE with a new one, rotating the previous record to
     * is_current=false.  Single authoritative write path — every code that
     * caches a fetched TLE must call this instead of writing tle_records directly.
     */
    public function upsertCurrentTle(string $line1, string $line2, string $source = 'celestrak'): TleRecord
    {
        $now = Carbon::now();

        $this->tleRecords()->where('is_current', true)->update(['is_current' => false]);

        return $this->tleRecords()->create([
            'line1'      => $line1,
            'line2'      => $line2,
            'epoch_at'   => $this->parseEpochFromLine1($line1),
            'source'     => $source,
            'fetched_at' => $now,
            'is_current' => true,
        ]);
    }

    /** Parse TLE epoch (line1 cols 18-32) into a datetime string. */
    public static function parseEpochFromLine1(string $line1): ?string
    {
        try {
            $epochStr = trim(substr($line1, 18, 14));
            if (! $epochStr || strlen($epochStr) < 3) {
                return null;
            }
            $year2   = (int) substr($epochStr, 0, 2);
            $dayFrac = (float) substr($epochStr, 2);
            $year    = $year2 >= 57 ? 1900 + $year2 : 2000 + $year2;

            return Carbon::create($year, 1, 1, 0, 0, 0, 'UTC')
                ->addDays($dayFrac - 1)
                ->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
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
