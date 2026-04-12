<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class ConjunctionController extends Controller
{
    public function index(string $noradId): JsonResponse
    {
        // Phase 1: simulated data (same logic as frontend mock)
        // Phase 2: replace with Space-Track CDM API
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
        $objects = [];

        for ($i = 0; $i < 9; $i++) {
            $missKm   = round(mt_rand(50, 900) + mt_rand(0, 99) / 100, 2);
            $prob     = round(1 / ($missKm * 0.4) * (mt_rand(1, 100) / 10000), 7);
            $riskScore = min(95, (int) round(100 / ($missKm * 0.08 + 1)));

            $objects[] = [
                'object_id'   => 'DEB-'.strtoupper(substr(md5($noradId.$i), 0, 5)),
                'miss_km'     => $missKm,
                'probability' => $prob,
                'risk_score'  => $riskScore,
                'risk_level'  => $riskScore > 60 ? 'HIGH' : ($riskScore > 30 ? 'MEDIUM' : 'LOW'),
                'tca'         => now()->addDays(mt_rand(0, 5))->toDateString(),
                'altitude_km' => mt_rand(300, 800),
            ];
        }

        usort($objects, fn ($a, $b) => $b['risk_score'] - $a['risk_score']);

        return $objects;
    }
}
