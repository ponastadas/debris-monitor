<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
            ->where('date', today()->startOfDay())
            ->value('count') ?? 0;
    }

    /**
     * Atomically record one request for the given identifier today.
     *
     * Uses a single-statement upsert so concurrent requests safely increment
     * the same row (ON DUPLICATE KEY UPDATE / ON CONFLICT DO UPDATE).
     *
     * Date values use startOfDay() to match the 'Y-m-d H:i:s' string that
     * Eloquent's `date` cast produces on SQLite (which has no native DATE type).
     * On MySQL, '2026-05-23 00:00:00' is coerced to DATE '2026-05-23' automatically.
     */
    public static function record(string $identifier): void
    {
        $today = today()->startOfDay()->toDateTimeString();
        $now = now()->toDateTimeString();

        static::upsert(
            [['identifier' => $identifier, 'date' => $today, 'count' => 1, 'created_at' => $now, 'updated_at' => $now]],
            ['identifier', 'date'],
            ['count' => DB::raw('count + 1'), 'updated_at' => $now]
        );
    }
}
