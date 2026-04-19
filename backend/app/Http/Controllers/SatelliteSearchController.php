<?php

namespace App\Http\Controllers;

use App\Models\Satellite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SatelliteSearchController extends Controller
{
    private const MAX_RESULTS = 10;

    /**
     * Search the local satellite catalog by NORAD ID or name.
     *
     * GET /api/satellites/search?q=ISS
     * GET /api/satellites/search?q=25544
     *
     * Returns up to 10 matches: [{norad_id, name}].
     * Served entirely from local DB — no live CelesTrak call.
     * Run `php artisan satellites:sync` to populate the catalog.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return $this->success([]);
        }

        $isNumeric = ctype_digit($q);

        if ($isNumeric) {
            // Exact NORAD ID match first, then prefix search
            $results = Satellite::where('norad_id', $q)
                ->orWhere('norad_id', 'like', "{$q}%")
                ->limit(self::MAX_RESULTS)
                ->get(['norad_id', 'name'])
                ->map(fn ($s) => ['norad_id' => $s->norad_id, 'name' => $s->name])
                ->values();
        } else {
            // Name search — exact prefix match scored above substring match
            $results = Satellite::where('name', 'like', "{$q}%")
                ->orWhere('name', 'like', "%{$q}%")
                ->orderByRaw("CASE WHEN name LIKE ? THEN 0 ELSE 1 END", ["{$q}%"])
                ->limit(self::MAX_RESULTS)
                ->get(['norad_id', 'name'])
                ->map(fn ($s) => ['norad_id' => $s->norad_id, 'name' => $s->name])
                ->values();
        }

        return $this->success($results);
    }
}
