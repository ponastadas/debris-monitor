<?php

namespace App\Http\Controllers;

use App\Models\ConjunctionAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    /**
     * List upcoming conjunction alerts for the authenticated user's
     * watched satellites, ordered by TCA ascending.
     */
    public function index(Request $request): JsonResponse
    {
        $noradIds = $request->user()
            ->watchedSatellites()
            ->pluck('norad_id');

        if ($noradIds->isEmpty()) {
            return response()->json([]);
        }

        $alerts = ConjunctionAlert::upcoming()
            ->whereIn('primary_norad_id', $noradIds)
            ->orderBy('tca')
            ->get()
            ->map(fn ($a) => [
                'id'                 => $a->id,
                'primary_norad_id'   => $a->primary_norad_id,
                'primary_name'       => $a->primary_name,
                'secondary_norad_id' => $a->secondary_norad_id,
                'secondary_name'     => $a->secondary_name,
                'tca'                => $a->tca->toIso8601String(),
                'hours_until_tca'    => round($a->hoursUntilTca(), 1),
                'miss_distance_km'   => $a->miss_distance_km,
                'probability'        => $a->probability,
                'risk_score'         => $a->risk_score,
                'risk_level'         => $a->riskLevel(),
            ]);

        return response()->json($alerts);
    }
}
