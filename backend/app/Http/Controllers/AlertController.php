<?php

namespace App\Http\Controllers;

use App\Models\ConjunctionAlert;
use App\Services\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    /**
     * List upcoming conjunction alerts for the authenticated user's
     * watched satellites, ordered by TCA ascending.
     *
     * Requires can_view_alerts entitlement (Starter plan or higher).
     */
    public function index(Request $request): JsonResponse
    {
        $entitlements = EntitlementService::forUser($request->user());

        if (! EntitlementService::can($entitlements, 'can_view_alerts')) {
            return $this->error(
                'ALERTS_NOT_AVAILABLE',
                'Conjunction alerts require a Starter plan or higher.',
                403,
            );
        }

        $sourceConfigured = ! empty(config('services.space_track.user'))
            && ! empty(config('services.space_track.pass'));

        $noradIds = $request->user()->watchedSatellites()->pluck('norad_id');

        $rows = $noradIds->isNotEmpty()
            ? ConjunctionAlert::upcoming()->whereIn('primary_norad_id', $noradIds)->orderBy('tca')->get()
            : collect();

        $alerts = $rows->map(fn ($a) => [
            'id' => $a->id,
            'primary_norad_id' => $a->primary_norad_id,
            'primary_name' => $a->primary_name,
            'secondary_norad_id' => $a->secondary_norad_id,
            'secondary_name' => $a->secondary_name,
            'tca' => $a->tca->toIso8601String(),
            'hours_until_tca' => round($a->hoursUntilTca(), 1),
            'miss_distance_km' => $a->miss_distance_km,
            'probability' => $a->probability,
            'risk_score' => $a->risk_score,
            'risk_level' => $a->riskLevel(),
            'source' => $a->source ?? 'sgp4',
        ]);

        $hasCdm = $rows->contains(fn ($a) => $a->source === 'space_track_cdm');
        $lastUpdated = $rows->max('updated_at');

        return response()->json([
            'success' => true,
            'meta' => [
                'source' => $rows->isEmpty() ? null : ($hasCdm ? 'space_track_cdm' : 'sgp4'),
                'source_configured' => $sourceConfigured,
                'last_updated' => $lastUpdated?->toIso8601String(),
                'coverage' => 'High-risk events · 5-day horizon',
            ],
            'data' => $alerts,
        ]);
    }
}
