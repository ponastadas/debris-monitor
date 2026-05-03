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
     * GET /api/satellites/search?q=1998-067A   (international designator)
     * GET /api/satellites/search?q=25544       (NORAD ID)
     *
     * Local DB only — results are cached for 300 seconds.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return $this->success([]);
        }

        $cacheKey = 'sat_search:' . md5(strtolower($q));

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
     * Text query: four strategies merged in priority order.
     *
     * 1. Standard prefix/substring LIKE
     * 2. Strip-normalised LIKE — "goes16" matches "GOES 16" (uses generated column)
     * 3. International designator LIKE — "1998-067A" finds ISS
     * 4. Alias prepend — "hubble" → HST
     */
    private function searchByText(string $q): array
    {
        $seen    = [];
        $results = [];

        // Strategy 1: standard name LIKE (prefix scored above substring)
        $standard = Satellite::whereHas('currentTle')
            ->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "{$q}%")
                   ->orWhere('name', 'like', "%{$q}%");
            })
            ->orderByRaw("CASE WHEN name LIKE ? THEN 0 ELSE 1 END", ["{$q}%"])
            ->limit(self::MAX_RESULTS)
            ->get(['norad_id', 'name']);

        foreach ($standard as $s) {
            if (! isset($seen[$s->norad_id])) {
                $seen[$s->norad_id] = true;
                $results[]          = ['norad_id' => $s->norad_id, 'name' => $s->name];
            }
        }

        // Strategy 2: strip-normalised match (uses name_normalized generated column)
        if (count($results) < self::MAX_RESULTS) {
            $stripped = $this->strip($q);

            if (strlen($stripped) >= 2) {
                $norm = Satellite::whereHas('currentTle')
                ->where(function ($qb) use ($stripped) {
                    $qb->where('name_normalized', 'like', "{$stripped}%")
                       ->orWhere('name_normalized', 'like', "%{$stripped}%");
                })
                ->orderByRaw(
                    "CASE WHEN name_normalized LIKE ? THEN 0 ELSE 1 END",
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
