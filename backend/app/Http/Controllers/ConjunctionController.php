<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class ConjunctionController extends Controller
{
    /**
     * Real NORAD IDs from three well-documented debris events.
     * Risk scores in this response are SIMULATED (Phase 1 — real CDM data comes in Phase 2).
     * These IDs let the frontend fetch genuine TLE and propagate real orbital positions via SGP4.
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

    public function index(string $noradId): JsonResponse
    {
        // Phase 1: simulated risk scores, real secondary NORAD IDs for SGP4 propagation.
        // Phase 2: replace risk data with real Space-Track CDM conjunction messages.
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

    private function generateSimulatedConjunctions(string $noradId): array
    {
        srand((int) $noradId); // deterministic per satellite for consistent demo
        $pool   = self::SECONDARY_NORAD_IDS;
        $offset = (int) $noradId % count($pool);
        $objects = [];

        for ($i = 0; $i < 9; $i++) {
            $missKm    = round(mt_rand(50, 900) + mt_rand(0, 99) / 100, 2);
            $prob      = round(1 / ($missKm * 0.4) * (mt_rand(1, 100) / 10000), 7);
            $riskScore = min(95, (int) round(100 / ($missKm * 0.08 + 1)));

            $objects[] = [
                'object_id'          => 'DEB-'.strtoupper(substr(md5($noradId.$i), 0, 5)),
                // Real NORAD ID — frontend can fetch TLE and propagate via SGP4.
                // Risk scores remain simulated until Space-Track CDM integration ships.
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
