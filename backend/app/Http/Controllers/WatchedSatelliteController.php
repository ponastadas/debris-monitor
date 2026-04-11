<?php

namespace App\Http\Controllers;

use App\Models\WatchedSatellite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WatchedSatelliteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $satellites = $request->user()
            ->watchedSatellites()
            ->orderBy('name')
            ->get(['id', 'norad_id', 'name', 'tle_fetched_at', 'created_at'])
            ->map(fn ($s) => [
                'id'             => $s->id,
                'norad_id'       => $s->norad_id,
                'name'           => $s->name,
                'tle_fresh'      => $s->hasFreshTle(),
                'watching_since' => $s->created_at->toIso8601String(),
            ]);

        return response()->json($satellites);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'norad_id' => 'required|string|max:10',
            'name'     => 'nullable|string|max:100',
        ]);

        $noradId = $request->input('norad_id');

        if ($request->user()->watchedSatellites()->where('norad_id', $noradId)->exists()) {
            return response()->json(['message' => 'Already watching this satellite.'], 409);
        }

        // Resolve name from CelesTrak if not provided
        $name = $request->input('name');
        if (! $name) {
            $tle  = $this->fetchTle($noradId);
            $name = $tle['name'] ?? $noradId;
        }

        $sat = $request->user()->watchedSatellites()->create([
            'norad_id' => $noradId,
            'name'     => $name,
        ]);

        return response()->json([
            'id'       => $sat->id,
            'norad_id' => $sat->norad_id,
            'name'     => $sat->name,
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $sat = $request->user()->watchedSatellites()->findOrFail($id);
        $sat->delete();

        return response()->json(['message' => 'Satellite removed from watch list.']);
    }

    private function fetchTle(string $noradId): ?array
    {
        try {
            $response = Http::timeout(8)->get(
                'https://celestrak.org/NORAD/elements/gp.php',
                ['CATNR' => $noradId, 'FORMAT' => 'TLE']
            );

            if (! $response->ok()) {
                return null;
            }

            $lines = array_values(array_filter(
                array_map('trim', explode("\n", trim($response->body()))),
                fn ($l) => $l !== ''
            ));

            return count($lines) >= 3
                ? ['name' => $lines[0], 'line1' => $lines[1], 'line2' => $lines[2]]
                : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
