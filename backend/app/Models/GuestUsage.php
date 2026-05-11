<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['identifier', 'date', 'count'])]
class GuestUsage extends Model
{
    // Eloquent default would be 'guest_usages'; the migration created 'guest_usage'.
    protected $table = 'guest_usage';

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /**
     * Today's request count for this identifier.
     */
    public static function todayCount(string $identifier): int
    {
        return static::where('identifier', $identifier)
            ->whereDate('date', today())
            ->value('count') ?? 0;
    }

    /**
     * Atomically record one request for the given identifier today.
     *
     * Two-step approach to avoid the TOCTOU race condition in first()/create():
     *   1. INSERT IGNORE — creates the row with count=0 if it doesn't exist.
     *      If two concurrent requests both try this, one silently does nothing.
     *   2. Atomic UPDATE SET count = count + 1 via Eloquent increment().
     *      Both concurrent requests safely increment the same row.
     *
     * Named `record()` rather than `increment()` to avoid shadowing the
     * non-static Eloquent\Model::increment() method (PHP enforces same signature).
     */
    public static function record(string $identifier): void
    {
        static::insertOrIgnore([
            'identifier' => $identifier,
            'date'       => today()->toDateString(),
            'count'      => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        static::where('identifier', $identifier)
            ->whereDate('date', today())
            ->increment('count');
    }
}
