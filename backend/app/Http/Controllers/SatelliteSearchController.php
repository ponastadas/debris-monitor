<?php

namespace App\Http\Controllers;

use App\Models\Satellite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SatelliteSearchController extends Controller
{
    private const MAX_RESULTS = 10;

    /**
     * Common-name → NORAD ID aliases for satellites whose TLE names have no
     * recognisable relation to the popular name a user would type.
     */
    private const ALIASES = [
        'hubble' => '20580',  // HST
        'hst' => '20580',
        'tiangong' => '48274',  // CSS TIANHE
        'tianhe' => '48274',
        'css' => '48274',
    ];

    /**
     * Search the satellite catalog by NORAD ID, name, or international designator.
     *
     * GET /api/satellites/search?q=ISS
     * GET /api/satellites/search?q=1998-067A   (international designator)
     * GET /api/satellites/search?q=25544       (NORAD ID)
     *
     * Primary strategy: MySQL FULLTEXT MATCH AGAINST (milliseconds).
     * Falls back to LIKE for queries shorter than the FULLTEXT min-token size (3).
     * Results are cached for 300 seconds.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return $this->success([]);
        }

        $cacheKey = 'sat_search:'.md5(strtolower($q));

        if ($cached = Cache::get($cacheKey)) {
            return $this->success($cached);
        }

        if (ctype_digit($q)) {
            $results = $this->searchByNorad($q);
            Cache::put($cacheKey, $results, 300);

            return $this->success($results);
        }

        $results = $this->searchByText($q);

        Cache::put($cacheKey, $results, 300);

        return $this->success($results);
    }

    // ── Local DB strategies ───────────────────────────────────────────────────

    private function searchByNorad(string $q): array
    {
        return Satellite::whereHas('currentTle')
            ->where(function ($qb) use ($q) {
                $qb->where('norad_id', $q)
                    ->orWhere('norad_id', 'like', "{$q}%");
            })
            ->limit(self::MAX_RESULTS)
            ->get(['norad_id', 'name'])
            ->map(fn ($s) => ['norad_id' => $s->norad_id, 'name' => $s->name])
            ->values()
            ->all();
    }

    /**
     * Text search via FULLTEXT for queries >= 3 chars, LIKE fallback for shorter ones.
     *
     * Priority order:
     * 1. FULLTEXT on (name, name_normalized) — handles "NOAA 19", "goes16", "ISS"
     * 2. LIKE fallback — only for 2-char queries that FULLTEXT minimum-token skips
     * 3. International designator LIKE — "1998-067A" finds ISS
     * 4. Alias prepend — "hubble" → HST
     */
    private function searchByText(string $q): array
    {
        $seen = [];
        $results = [];

        // Strategy 1: FULLTEXT on name + name_normalized (fast, uses index)
        if (strlen($q) >= 3) {
            foreach ($this->searchByFullText($q) as $r) {
                $seen[$r['norad_id']] = true;
                $results[] = $r;
            }
        }

        // Strategy 2: LIKE fallback — only for 2-char queries (FULLTEXT skips tokens < 3 chars)
        // or when FULLTEXT found nothing at all. Never supplement a non-empty FULLTEXT result
        // with LIKE, as that would run an un-indexed full-table scan and kill latency.
        if (empty($results)) {
            foreach ($this->searchByLike($q, $seen) as $r) {
                $seen[$r['norad_id']] = true;
                $results[] = $r;
            }
        }

        // Strategy 3: international designator
        if (count($results) < self::MAX_RESULTS) {
            foreach ($this->searchByDesignator($q, $seen) as $r) {
                $seen[$r['norad_id']] = true;
                $results[] = $r;
            }
        }

        // Strategy 4: alias prepend
        $aliasId = $this->resolveAlias($q);

        if ($aliasId !== null) {
            if (! isset($seen[$aliasId])) {
                $aliasSat = Satellite::whereHas('currentTle')
                    ->where('norad_id', $aliasId)
                    ->get(['norad_id', 'name'])
                    ->map(fn ($s) => ['norad_id' => $s->norad_id, 'name' => $s->name])
                    ->first();

                if ($aliasSat) {
                    array_unshift($results, $aliasSat);
                }
            } else {
                $aliasRow = array_filter($results, fn ($r) => $r['norad_id'] === $aliasId);
                $rest = array_filter($results, fn ($r) => $r['norad_id'] !== $aliasId);
                $results = array_values(array_merge(array_values($aliasRow), array_values($rest)));
            }
        }

        return array_slice(array_values($results), 0, self::MAX_RESULTS);
    }

    /**
     * FULLTEXT search on name + name_normalized columns.
     *
     * Converts the query to boolean-mode prefix terms: "NOAA 19" → "NOAA* 19*".
     * This handles both "iss" → ISS and "goes16" → "GOES 16" (via name_normalized).
     * Relevance-ranked: exact-prefix matches score higher than mid-word matches.
     *
     * Minimum effective token length is 3 (MySQL innodb_ft_min_token_size default).
     *
     * @return list<array{norad_id: string, name: string}>
     */
    private function searchByFullText(string $q): array
    {
        $terms = preg_split('/\s+/', trim($q), -1, PREG_SPLIT_NO_EMPTY);
        // Use AND mode (+term*): every typed word must appear in the name.
        // "NOAA 19" → "+NOAA* +19*" finds only NOAA 19, not every satellite with "19".
        $ftQuery = '+'.implode('* +', $terms).'*';

        return Satellite::whereHas('currentTle')
            ->whereRaw('MATCH(name, name_normalized) AGAINST(? IN BOOLEAN MODE)', [$ftQuery])
            ->orderByRaw(
                // Primary: FULLTEXT relevance. Secondary: boost names that actually start with the query.
                'MATCH(name, name_normalized) AGAINST(? IN BOOLEAN MODE) DESC, CASE WHEN name LIKE ? THEN 0 ELSE 1 END ASC',
                [$ftQuery, "{$q}%"]
            )
            ->limit(self::MAX_RESULTS)
            ->get(['norad_id', 'name'])
            ->map(fn ($s) => ['norad_id' => $s->norad_id, 'name' => $s->name])
            ->values()
            ->all();
    }

    /**
     * LIKE fallback — used only when FULLTEXT is skipped (< 3-char query) or returns too few results.
     *
     * @param  array<string,bool>  $skip
     * @return list<array{norad_id: string, name: string}>
     */
    private function searchByLike(string $q, array $skip): array
    {
        $rows = Satellite::whereHas('currentTle')
            ->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "{$q}%")
                    ->orWhere('name', 'like', "%{$q}%");
            })
            ->orderByRaw('CASE WHEN name LIKE ? THEN 0 ELSE 1 END', ["{$q}%"])
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

        $rows = Satellite::whereHas('currentTle')
            ->whereNotNull('designator_normalized')
            ->where('designator_normalized', 'like', "{$norm}%")
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
