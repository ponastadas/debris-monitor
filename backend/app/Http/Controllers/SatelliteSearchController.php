<?php

namespace App\Http\Controllers;

use App\Models\Satellite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SatelliteSearchController extends Controller
{
    private const MAX_RESULTS = 10;
    private const CELESTRAK_URL = 'https://celestrak.org/NORAD/elements/gp.php';

    /**
     * Common-name → NORAD ID aliases for satellites whose TLE names have no
     * recognisable relation to the popular name a user would type.
     * Keep minimal — strip-normalisation handles most separator variations.
     */
    private const ALIASES = [
        'hubble'   => '20580',  // HST
        'hst'      => '20580',
        'tiangong' => '48274',  // CSS TIANHE
        'tianhe'   => '48274',
        'css'      => '48274',
    ];

    /**
     * Search the satellite catalog by NORAD ID, name, or international designator.
     *
     * GET /api/satellites/search?q=ISS
     * GET /api/satellites/search?q=horacio
     * GET /api/satellites/search?q=1998-067A   (international designator)
     * GET /api/satellites/search?q=25544       (NORAD ID)
     *
     * Local DB first; falls back to live CelesTrak queries when local returns
     * nothing, then caches any found satellites for future lookups.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return $this->success([]);
        }

        if (ctype_digit($q)) {
            $results = $this->searchByNorad($q);
            if (empty($results)) {
                $results = $this->celestrakByNorad($q);
            }
            return $this->success($results);
        }

        $results = $this->searchByText($q);

        if (empty($results)) {
            // Check negative cache before hitting CelesTrak — a confirmed miss
            // within the last 60s doesn't warrant another live call.
            $missKey = 'search_miss:' . $this->strip($q);

            if (! Cache::has($missKey)) {
                $results = $this->celestrakFallback($q);

                if (empty($results)) {
                    Cache::put($missKey, true, 60);
                }
            }
        }

        return $this->success($results);
    }

    // ── Local DB strategies ───────────────────────────────────────────────────

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
     * 1. Standard prefix/substring LIKE
     * 2. Strip-normalised LIKE — "goes16" matches "GOES 16"
     * 3. International designator LIKE — "1998-067A" finds ISS
     * 4. Alias prepend — "hubble" → HST
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

        // Strategy 2: strip-normalised match
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

        // Strategy 3: international designator
        if (count($results) < self::MAX_RESULTS) {
            foreach ($this->searchByDesignator($q, $seen) as $r) {
                $seen[$r['norad_id']] = true;
                $results[]            = $r;
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
                $aliasRow = array_filter($results, fn ($r) => $r['norad_id'] === $aliasId);
                $rest     = array_filter($results, fn ($r) => $r['norad_id'] !== $aliasId);
                $results  = array_values(array_merge(array_values($aliasRow), array_values($rest)));
            }
        }

        return array_slice(array_values($results), 0, self::MAX_RESULTS);
    }

    /**
     * @param  array<string,bool>  $skip
     * @return list<array{norad_id: string, name: string}>
     */
    private function searchByDesignator(string $q, array $skip): array
    {
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

    // ── Live CelesTrak fallbacks (cache results to DB) ────────────────────────

    /**
     * Fallback for numeric NORAD ID queries: CATNR= endpoint is exact and fast.
     *
     * @return list<array{norad_id: string, name: string}>
     */
    private function celestrakByNorad(string $noradId): array
    {
        $parsed = $this->celestrakFetch(['CATNR' => $noradId]);

        if (empty($parsed)) {
            return [];
        }

        $this->cacheSatellites($parsed);

        return array_map(fn ($p) => ['norad_id' => $p['norad_id'], 'name' => $p['name']], $parsed);
    }

    /**
     * Fallback for text/designator queries when local DB returns nothing.
     *
     * Tries in order:
     *   1. NAME=<q>                              — exact as typed
     *   2. NAME=<stripped-uppercase>             — "Quick-3" → "QUICK3"
     *   3. INTDES=<q> (if looks like designator) — "2020-081J" → INTDES query
     *
     * @return list<array{norad_id: string, name: string}>
     */
    private function celestrakFallback(string $q): array
    {
        $candidates = [['NAME' => $q]];

        $stripped = strtoupper($this->strip($q));
        if ($stripped !== strtoupper($q) && $stripped !== '') {
            $candidates[] = ['NAME' => $stripped];
        }

        // If the query looks like an international designator (e.g. "2020-081J")
        // also try the INTDES= parameter which is more precise than NAME=.
        if (preg_match('/^\d{4}-\d{3}[A-Z]*$/i', $q) || preg_match('/^\d{2}-\d{3}[A-Z]*$/i', $q)) {
            $candidates[] = ['INTDES' => $q];
        }

        foreach ($candidates as $params) {
            $parsed = $this->celestrakFetch($params);

            if (! empty($parsed)) {
                $this->cacheSatellites($parsed);

                return array_slice(
                    array_map(fn ($p) => ['norad_id' => $p['norad_id'], 'name' => $p['name']], $parsed),
                    0,
                    self::MAX_RESULTS
                );
            }
        }

        return [];
    }

    /**
     * Execute a single CelesTrak GP query and return parsed TLE records.
     * Returns [] on any error or empty response.
     *
     * @param  array<string,string>  $params
     * @return list<array{name: string, norad_id: string, line1: string, line2: string}>
     */
    private function celestrakFetch(array $params): array
    {
        try {
            $response = Http::timeout(10)->get(self::CELESTRAK_URL, array_merge($params, ['FORMAT' => 'TLE']));
        } catch (\Throwable) {
            return [];
        }

        if (! $response->ok()) {
            return [];
        }

        $body = trim($response->body());

        if (! $body || str_contains($body, 'No GP data')) {
            return [];
        }

        return $this->parseTleLines($body);
    }

    /**
     * Upsert satellites from a live fetch into the local catalog so subsequent
     * searches are served from DB.
     *
     * @param list<array{name: string, norad_id: string, line1: string, line2: string}> $parsed
     */
    private function cacheSatellites(array $parsed): void
    {
        $now = now();

        foreach ($parsed as $p) {
            $satellite = Satellite::updateOrCreate(
                ['norad_id' => $p['norad_id']],
                ['name' => $p['name'], 'catalog_source' => 'celestrak', 'last_seen_at' => $now]
            );

            $satellite->upsertCurrentTle($p['line1'], $p['line2']);
        }
    }

    /**
     * Parse raw TLE body (3-line sets) into structured records.
     *
     * @return list<array{name: string, norad_id: string, line1: string, line2: string}>
     */
    private function parseTleLines(string $body): array
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $body)),
            fn ($l) => $l !== ''
        ));

        $results = [];

        for ($i = 0; $i + 2 < count($lines); $i += 3) {
            $name  = $lines[$i];
            $line1 = $lines[$i + 1];
            $line2 = $lines[$i + 2];

            if (! str_starts_with($line1, '1 ') || ! str_starts_with($line2, '2 ')) {
                continue;
            }

            $noradId = trim(substr($line1, 2, 5));

            if (! $noradId || ! ctype_digit($noradId)) {
                continue;
            }

            $results[] = ['name' => $name, 'norad_id' => $noradId, 'line1' => $line1, 'line2' => $line2];
        }

        return $results;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function strip(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($s));
    }

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
