<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CatalogController extends Controller
{
    /**
     * Maps satellites.object_type (DB) → frontend type token.
     * Used in both directions: TYPE_MAP for output, REVERSE_TYPE_MAP for ?types= input.
     */
    private const TYPE_MAP = [
        'satellite'   => 'satellite',
        'debris'      => 'debris',
        'rocket_body' => 'rocket',
    ];

    private const REVERSE_TYPE_MAP = [
        'satellite' => 'satellite',
        'debris'    => 'debris',
        'rocket'    => 'rocket_body',
    ];

    /**
     * Return locally-synced satellites with their current TLE records.
     *
     * GET /api/catalog
     * GET /api/catalog?types=satellite,debris
     *
     * Public — no auth, no rate limit, no guest quota.
     *
     * Caching strategy:
     *   Cache-Control: public, max-age=3600 — browsers/CDN cache the full response for 1 hour.
     *   ETag: md5 of the last sync timestamp — after max-age expires, browsers send
     *   If-None-Match; if the catalog hasn't changed since, we return 304 (zero bytes transferred).
     *
     * Query parameters:
     *   types   Comma-separated frontend type names: satellite, debris, rocket, unknown.
     *           Omit for all types (default).
     *
     * Returns an empty satellites array when the catalog has not been synced yet.
     * Callers should fall back to CelesTrak in that case.
     */
    public function index(Request $request): JsonResponse|Response
    {
        // ── ETag — lightweight check before loading catalog rows ──────────────
        $syncedAt = DB::table('tle_records')->where('is_current', true)->max('fetched_at');
        $etag     = '"'.md5($syncedAt ?? 'empty').'"';

        if ($request->header('If-None-Match') === $etag) {
            return response(null, 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age=3600');
        }

        // ── Build base query ──────────────────────────────────────────────────
        $query = DB::table('satellites as s')
            ->join('tle_records as t', function ($join) {
                $join->on('t.satellite_id', '=', 's.id')->where('t.is_current', true);
            })
            ->select('s.name', 's.object_type', 't.line1', 't.line2')
            ->orderBy('s.norad_id');

        // ── ?types= filter ────────────────────────────────────────────────────
        $typesParam = $request->query('types');
        if ($typesParam) {
            $dbTypes = collect(explode(',', $typesParam))
                ->map(fn ($t) => self::REVERSE_TYPE_MAP[trim($t)] ?? null)
                ->filter()
                ->values()
                ->all();

            // Apply whereIn even when $dbTypes is empty — unknown tokens match nothing.
            // Laravel generates WHERE 0 = 1 for empty arrays, returning no rows.
            $query->whereIn('s.object_type', $dbTypes);
        }

        $rows = $query->get();

        $satellites = $rows->map(fn ($r) => [
            'name'  => $r->name,
            'type'  => self::TYPE_MAP[$r->object_type] ?? 'unknown',
            'line1' => $r->line1,
            'line2' => $r->line2,
        ])->values();

        return $this->success([
            'satellites' => $satellites,
            'count'      => $satellites->count(),
            'synced_at'  => $syncedAt,
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=3600',
            'ETag'          => $etag,
        ]);
    }
}
