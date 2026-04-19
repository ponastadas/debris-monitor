<?php

namespace App\Http\Controllers;

use App\Models\Satellite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SatelliteSearchController extends Controller
{
    private const MAX_RESULTS = 10;

    /**
     * Common-name → NORAD ID aliases for satellites whose TLE names have no
     * recognisable relation to the popular name a user would type.
     * Keep this list minimal — strip-normalisation handles most "goes16"/"goes-16"
     * style variations automatically.
     */
    private const ALIASES = [
        'hubble' => '20580',  // HST — official name gives no hint
        'hst'    => '20580',
        'tiangong' => '48274', // CSS TIANHE — common tourist name vs. TLE name
        'tianhe'   => '48274',
        'css'      => '48274',
    ];

    /**
     * Search the local satellite catalog by NORAD ID or name.
     *
     * GET /api/satellites/search?q=ISS
     * GET /api/satellites/search?q=goes16
     * GET /api/satellites/search?q=sentinel-1a
     * GET /api/satellites/search?q=1998-067A        (international designator)
     * GET /api/satellites/search?q=25544            (NORAD ID)
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

        if (ctype_digit($q)) {
            return $this->success($this->searchByNorad($q));
        }

        return $this->success($this->searchByText($q));
    }

    // ── Search strategies ─────────────────────────────────────────────────────

    /**
     * Numeric query: exact NORAD ID first, then prefix match.
     */
    private function searchByNorad(string $q): array
    {
        return Satellite::where('norad_id', $q)
            ->orWhere('norad_id', 'like', "{$q}%")
            ->limit(self::MAX_RESULTS)
            ->get(['norad_id', 'name'])
            ->map(fn ($s) => ['norad_id' => $s->norad_id, 'name' => $s->name])
            ->values()
            ->all();
    }

    /**
     * Text query: four strategies merged in priority order.
     *
     * 1. Standard prefix/substring LIKE (fast, covers most cases)
     * 2. Strip-normalised LIKE — "goes16" matches "GOES 16", "sentinel1a" matches "SENTINEL-1A"
     * 3. International designator LIKE — "1998-067A", "98067A", "98-067A"
     * 4. Alias prepend — "hubble" → HST (common names with no TLE hint)
     */
    private function searchByText(string $q): array
    {
        $seen    = [];
        $results = [];

        // Strategy 1: standard name LIKE (prefix scored above substring)
        $standard = Satellite::where('name', 'like', "{$q}%")
            ->orWhere('name', 'like', "%{$q}%")
            ->orderByRaw("CASE WHEN name LIKE ? THEN 0 ELSE 1 END", ["{$q}%"])
            ->limit(self::MAX_RESULTS)
            ->get(['norad_id', 'name']);

        foreach ($standard as $s) {
            if (! isset($seen[$s->norad_id])) {
                $seen[$s->norad_id] = true;
                $results[]          = ['norad_id' => $s->norad_id, 'name' => $s->name];
            }
        }

        // Strategy 2: strip-normalised match — handles separators/spacing differences
        if (count($results) < self::MAX_RESULTS) {
            $stripped = $this->strip($q);

            if (strlen($stripped) >= 2) {
                $norm = Satellite::whereRaw(
                    "REGEXP_REPLACE(LOWER(name), '[^a-z0-9]', '') LIKE ?",
                    ["{$stripped}%"]
                )
                ->orWhereRaw(
                    "REGEXP_REPLACE(LOWER(name), '[^a-z0-9]', '') LIKE ?",
                    ["%{$stripped}%"]
                )
                ->orderByRaw(
                    "CASE WHEN REGEXP_REPLACE(LOWER(name), '[^a-z0-9]', '') LIKE ? THEN 0 ELSE 1 END",
                    ["{$stripped}%"]
                )
                ->limit(self::MAX_RESULTS)
                ->get(['norad_id', 'name']);

                foreach ($norm as $s) {
                    if (! isset($seen[$s->norad_id])) {
                        $seen[$s->norad_id] = true;
                        $results[]          = ['norad_id' => $s->norad_id, 'name' => $s->name];
                    }
                }
            }
        }

        // Strategy 3: international designator search
        if (count($results) < self::MAX_RESULTS) {
            $desigRows = $this->searchByDesignator($q, $seen);
            foreach ($desigRows as $r) {
                if (! isset($seen[$r['norad_id']])) {
                    $seen[$r['norad_id']] = true;
                    $results[]            = $r;
                }
            }
        }

        // Strategy 4: alias prepend
        $aliasId = $this->resolveAlias($q);

        if ($aliasId !== null) {
            if (! isset($seen[$aliasId])) {
                $aliasSat = Satellite::where('norad_id', $aliasId)
                    ->get(['norad_id', 'name'])
                    ->map(fn ($s) => ['norad_id' => $s->norad_id, 'name' => $s->name])
                    ->first();

                if ($aliasSat) {
                    array_unshift($results, $aliasSat);
                }
            } else {
                // Already in results — move it to front
                $aliasRow = array_filter($results, fn ($r) => $r['norad_id'] === $aliasId);
                $rest     = array_filter($results, fn ($r) => $r['norad_id'] !== $aliasId);
                $results  = array_values(array_merge(array_values($aliasRow), array_values($rest)));
            }
        }

        return array_slice(array_values($results), 0, self::MAX_RESULTS);
    }

    /**
     * Match international designator column against several normalised formats.
     * Input "1998-067A", "98-067A", and "98067A" should all match NORAD 25544.
     *
     * @param  array<string,bool>  $skip  Already-seen norad_ids to exclude
     * @return list<array{norad_id: string, name: string}>
     */
    private function searchByDesignator(string $q, array $skip): array
    {
        // Normalise input to bare alphanumeric (e.g. "1998-067A" → "1998067a")
        $norm = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $q));

        if (strlen($norm) < 4) {
            return [];
        }

        $rows = Satellite::whereNotNull('international_designator')
            ->whereRaw(
                "REGEXP_REPLACE(LOWER(international_designator), '[^a-z0-9]', '') LIKE ?",
                ["{$norm}%"]
            )
            ->limit(self::MAX_RESULTS)
            ->get(['norad_id', 'name']);

        $out = [];
        foreach ($rows as $s) {
            if (! isset($skip[$s->norad_id])) {
                $out[] = ['norad_id' => $s->norad_id, 'name' => $s->name];
            }
        }

        return $out;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Strip all non-alphanumeric characters and lowercase — used for
     * normalised comparison ("GOES-16" → "goes16", "Sentinel 1A" → "sentinel1a").
     */
    private function strip(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($s));
    }

    /**
     * Return a NORAD ID if the query matches a known alias, null otherwise.
     * Checks exact match and prefix match against the alias keys.
     */
    private function resolveAlias(string $q): ?string
    {
        $lower = strtolower(trim($q));

        foreach (self::ALIASES as $alias => $id) {
            if ($lower === $alias || str_starts_with($alias, $lower)) {
                return $id;
            }
        }

        return null;
    }
}
