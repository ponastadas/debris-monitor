<?php

namespace App\Http\Controllers;

use App\Models\Satellite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SatelliteSearchController extends Controller
{
    private const MAX_RESULTS = 10;

    /**
     * Common-name → NORAD ID aliases for satellites whose TLE names don't match
     * the popular names users type. Checked when a name search returns no results.
     * Keys are lowercase; values are NORAD IDs.
     */
    private const ALIASES = [
        'hubble'            => '20580',  // HST
        'hub'               => '20580',
        'tiangong'          => '48274',  // CSS (TIANHE) — core module
        'tianhe'            => '48274',
        'css'               => '48274',
        'chinese space'     => '48274',
        'goes-16'           => '41866',
        'goes 16'           => '41866',
        'goes-17'           => '43226',
        'goes 17'           => '43226',
        'goes-18'           => '51850',
        'goes 18'           => '51850',
        'landsat'           => '39084',  // Landsat 8 (most recent active)
        'terra'             => '25994',
        'aqua'              => '27540',
        'sentinel'          => '38857',  // Sentinel-1A
        'envisat'           => '27386',
        'noaa-15'           => '25338',
        'noaa-18'           => '28654',
        'noaa-19'           => '33591',
        'noaa-20'           => '43013',
        'jpss'              => '43013',
        'metop'             => '29500',  // MetOp-A
        'starlink'          => null,     // too many — fall through to DB search
        'iridium'           => null,
    ];

    /**
     * Search the local satellite catalog by NORAD ID or name.
     *
     * GET /api/satellites/search?q=ISS
     * GET /api/satellites/search?q=hubble
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

            // If DB name search returned nothing, check the common-name alias table.
            // Many satellites have obscure TLE names (e.g. HST, CSS) that don't match
            // the popular names users type (e.g. Hubble, Tiangong).
            if ($results->isEmpty()) {
                $lower   = strtolower($q);
                $noradId = null;

                foreach (self::ALIASES as $alias => $id) {
                    if ($id !== null && str_starts_with($alias, $lower) || $lower === $alias) {
                        $noradId = $id;
                        break;
                    }
                }

                if ($noradId) {
                    $aliasResult = Satellite::where('norad_id', $noradId)
                        ->get(['norad_id', 'name'])
                        ->map(fn ($s) => ['norad_id' => $s->norad_id, 'name' => $s->name])
                        ->values();
                    $results = $aliasResult;
                }
            }
        }

        return $this->success($results);
    }
}
