<?php

namespace App\Http\Controllers;

use App\Models\ConjunctionEvent;
use Illuminate\Http\JsonResponse;

class ConjunctionController extends Controller
{
    /**
     * Return nearby conjunction events for a given NORAD ID.
     *
     * Data priority:
     *   1. Real Space-Track CDM events from conjunction_events table.
     *      source = 'space_track_cdm' — populated by `php artisan conjunctions:sync`.
     *   2. Simulated fallback (deterministic, real secondary NORAD IDs).
     *      source = 'simulated' — used when CDM data is unavailable.
     *
     * The frontend renders an honest badge based on the `source` field:
     *   'space_track_cdm' → "LIVE CDM DATA"
     *   'simulated'        → "SIMULATED RISK · REAL NORAD IDs"
     *
     * GET /api/conjunctions/{noradId}
     * Handled by HandlePublicRequest — guest/user/API-key quota enforced upstream.
     */
    public function index(string $noradId): JsonResponse
    {
        // ── 1. Try real CDM data ──────────────────────────────────────────
        $events = ConjunctionEvent::active()
            ->forObject($noradId)
            ->orderBy('min_range_km')
            ->limit(10)
            ->get();

        if ($events->isNotEmpty()) {
            $objects = $events->map(fn ($e) => $this->eventToObject($e, $noradId));

            return response()->json([
                'success' => true,
                'data'    => [
                    'norad_id'     => $noradId,
                    'object_count' => $objects->count(),
                    'computed_at'  => now()->toIso8601String(),
                    'source'       => 'space_track_cdm',
                    'objects'      => $objects,
                ],
            ]);
        }

        // ── 2. Simulated fallback ─────────────────────────────────────────
        $objects = $this->generateSimulatedConjunctions($noradId);

        return response()->json([
            'success' => true,
            'data'    => [
                'norad_id'     => $noradId,
                'object_count' => count($objects),
                'computed_at'  => now()->toIso8601String(),
                'source'       => 'simulated',
                'objects'      => $objects,
            ],
        ]);
    }

    // ── CDM mapping ───────────────────────────────────────────────────────

    /**
     * Map a ConjunctionEvent to the conjunction object shape expected by the frontend.
     * $perspectiveNoradId drives which satellite is "primary" vs "secondary".
     */
    private function eventToObject(ConjunctionEvent $event, string $perspectiveNoradId): array
    {
        // Determine which sat is "the other one" from the caller's perspective.
        if ($event->sat1_norad_id === $perspectiveNoradId) {
            $secondaryNoradId = $event->sat2_norad_id;
        } else {
            $secondaryNoradId = $event->sat1_norad_id;
        }

        $riskScore = $event->riskScore();

        return [
            'object_id'          => 'CDM-' . $event->cdm_id,
            'secondary_norad_id' => $secondaryNoradId,
            'miss_km'            => $event->min_range_km,
            'probability'        => $event->probability,
            'risk_score'         => $riskScore,
            'risk_level'         => $riskScore >= 70 ? 'HIGH' : ($riskScore >= 40 ? 'MEDIUM' : 'LOW'),
            'tca'                => $event->tca->toDateString(),
            'altitude_km'        => null,   // not provided in CDM; frontend handles null gracefully
        ];
    }

    // ── Simulated fallback ────────────────────────────────────────────────

    /**
     * Real NORAD IDs from three well-documented debris events.
     * Risk scores in this response are SIMULATED — used only when real CDM is unavailable.
     *
     * Sources:
     *   Fengyun-1C ASAT (Jan 2007)   – ~850 km SSO, starting NORAD 29228
     *   Cosmos 2251 collision (2009) – mixed altitudes after years of decay, starting 33764
     *   Iridium 33 collision (2009)  – originally ~780 km, starting 33438
     */
    private const SECONDARY_NORAD_IDS = [
        '29228', '29230', '29232', '29234', '29236', '29238', '29240', '29242', '29244',
        '33764', '33766', '33768', '33770', '33772', '33774', '33776', '33778', '33780',
        '33438', '33440', '33442', '33444', '33446', '33448', '33450', '33452', '33454',
    ];

    private function generateSimulatedConjunctions(string $noradId): array
    {
        srand((int) $noradId); // deterministic per satellite for consistent demo
        $pool    = self::SECONDARY_NORAD_IDS;
        $offset  = (int) $noradId % count($pool);
        $objects = [];

        for ($i = 0; $i < 9; $i++) {
            $missKm    = round(mt_rand(50, 900) + mt_rand(0, 99) / 100, 2);
            $prob      = round(1 / ($missKm * 0.4) * (mt_rand(1, 100) / 10000), 7);
            $riskScore = min(95, (int) round(100 / ($missKm * 0.08 + 1)));

            $objects[] = [
                'object_id'          => 'DEB-'.strtoupper(substr(md5($noradId.$i), 0, 5)),
                'secondary_norad_id' => $pool[($offset + $i) % count($pool)],
                'miss_km'            => $missKm,
                'probability'        => $prob,
                'risk_score'         => $riskScore,
                'risk_level'         => $riskScore > 60 ? 'HIGH' : ($riskScore > 30 ? 'MEDIUM' : 'LOW'),
                'tca'                => now()->addDays(mt_rand(0, 5))->toDateString(),
                'altitude_km'        => mt_rand(300, 800),
            ];
        }

        usort($objects, fn ($a, $b) => $b['risk_score'] - $a['risk_score']);

        return $objects;
    }
}
