<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class SatelliteController extends Controller
{
    public function show(string $noradId): JsonResponse
    {
        $tle = $this->fetchTle($noradId);

        if (! $tle) {
            return response()->json(['error' => 'Satellite not found'], 404);
        }

        return response()->json([
            'norad_id' => $noradId,
            'name'     => $tle['name'],
            'tle_line1' => $tle['line1'],
            'tle_line2' => $tle['line2'],
            'source'   => 'celestrak',
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    public function orbit(string $noradId): JsonResponse
    {
        // Orbital path computation will go here
        // For now returns the TLE so frontend can propagate
        $tle = $this->fetchTle($noradId);

        if (! $tle) {
            return response()->json(['error' => 'Satellite not found'], 404);
        }

        return response()->json([
            'norad_id'  => $noradId,
            'tle_line1' => $tle['line1'],
            'tle_line2' => $tle['line2'],
        ]);
    }

    private function fetchTle(string $noradId): ?array
    {
        $response = Http::timeout(10)->get(
            "https://celestrak.org/NORAD/elements/gp.php?CATNR={$noradId}&FORMAT=TLE"
        );

        if (! $response->ok()) {
            return null;
        }

        $lines = array_values(array_filter(
            explode("\n", trim($response->body())),
            fn ($l) => trim($l) !== ''
        ));

        if (count($lines) < 3) {
            return null;
        }

        return [
            'name'  => trim($lines[0]),
            'line1' => trim($lines[1]),
            'line2' => trim($lines[2]),
        ];
    }
}
