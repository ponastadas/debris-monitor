<?php

namespace App\Http\Controllers;

use App\Models\Satellite;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class SatelliteController extends Controller
{
    public function show(string $noradId): JsonResponse
    {
        $tle = $this->getLocalTle($noradId) ?? $this->fetchAndCacheTle($noradId);

        if (! $tle) {
            return response()->json(['error' => 'Satellite not found'], 404);
        }

        return $this->success([
            'norad_id'   => $noradId,
            'name'       => $tle['name'],
            'tle_line1'  => $tle['line1'],
            'tle_line2'  => $tle['line2'],
            'source'     => $tle['source'],
            'fetched_at' => $tle['fetched_at'],
        ]);
    }

    public function orbit(string $noradId): JsonResponse
    {
        $tle = $this->getLocalTle($noradId) ?? $this->fetchAndCacheTle($noradId);

        if (! $tle) {
            return response()->json(['error' => 'Satellite not found'], 404);
        }

        return $this->success([
            'norad_id'  => $noradId,
            'tle_line1' => $tle['line1'],
            'tle_line2' => $tle['line2'],
        ]);
    }

    /**
     * Return TLE from local DB if it exists and was fetched within 6 hours.
     * Returns null when no usable local record is found.
     */
    private function getLocalTle(string $noradId): ?array
    {
        $satellite = Satellite::with('currentTle')->where('norad_id', $noradId)->first();

        if (! $satellite || ! $satellite->currentTle) {
            return null;
        }

        $record = $satellite->currentTle;

        if ($record->fetched_at->diffInHours(now()) > 6) {
            return null;
        }

        return [
            'name'       => $satellite->name,
            'line1'      => $record->line1,
            'line2'      => $record->line2,
            'source'     => 'local',
            'fetched_at' => $record->fetched_at->toIso8601String(),
        ];
    }

    /**
     * Fetch TLE from CelesTrak, store it locally, and return it.
     * This fallback runs only when local data is missing or stale.
     * Stores the result so the same satellite is served locally on subsequent requests.
     */
    private function fetchAndCacheTle(string $noradId): ?array
    {
        try {
            $response = Http::timeout(10)->get(
                'https://celestrak.org/NORAD/elements/gp.php',
                ['CATNR' => $noradId, 'FORMAT' => 'TLE']
            );
        } catch (\Throwable) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", trim($response->body()))),
            fn ($l) => $l !== ''
        ));

        if (count($lines) < 3 || ! str_starts_with($lines[1], '1 ') || ! str_starts_with($lines[2], '2 ')) {
            return null;
        }

        $name  = $lines[0];
        $line1 = $lines[1];
        $line2 = $lines[2];
        $now   = now();

        // Upsert satellite record
        $satellite = Satellite::updateOrCreate(
            ['norad_id' => $noradId],
            ['name' => $name, 'catalog_source' => 'celestrak', 'last_seen_at' => $now]
        );

        // Replace current TLE
        $satellite->tleRecords()->where('is_current', true)->update(['is_current' => false]);
        $satellite->tleRecords()->create([
            'line1'      => $line1,
            'line2'      => $line2,
            'epoch_at'   => $this->parseEpoch($line1),
            'source'     => 'celestrak',
            'fetched_at' => $now,
            'is_current' => true,
        ]);

        return [
            'name'       => $name,
            'line1'      => $line1,
            'line2'      => $line2,
            'source'     => 'celestrak',
            'fetched_at' => $now->toIso8601String(),
        ];
    }

    private function parseEpoch(string $line1): ?string
    {
        try {
            $epochStr = trim(substr($line1, 18, 14));
            if (! $epochStr || strlen($epochStr) < 3) {
                return null;
            }
            $year2   = (int) substr($epochStr, 0, 2);
            $dayFrac = (float) substr($epochStr, 2);
            $year    = $year2 >= 57 ? 1900 + $year2 : 2000 + $year2;

            return now()->setTimezone('UTC')->setDate($year, 1, 1)->startOfDay()
                ->addDays($dayFrac - 1)
                ->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
