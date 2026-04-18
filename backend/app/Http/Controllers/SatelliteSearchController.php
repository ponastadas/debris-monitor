<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SatelliteSearchController extends Controller
{
    private const CELESTRAK_URL = 'https://celestrak.org/NORAD/elements/gp.php';
    private const MAX_RESULTS   = 10;

    /**
     * Search for satellites by name or NORAD ID.
     *
     * GET /api/satellites/search?q=ISS
     * GET /api/satellites/search?q=25544
     *
     * Returns up to 10 matches: [{norad_id, name}].
     * No auth required — sits behind HandlePublicRequest (guest quota applies).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (strlen($q) < 2) {
            return $this->success([]);
        }

        $isNumeric = ctype_digit($q);

        try {
            $param    = $isNumeric ? ['CATNR' => $q] : ['NAME' => $q];
            $response = Http::timeout(8)->get(self::CELESTRAK_URL, array_merge($param, ['FORMAT' => 'TLE']));
        } catch (\Throwable) {
            return $this->success([]);
        }

        if (! $response->ok()) {
            return $this->success([]);
        }

        $body = trim($response->body());

        if (! $body || str_contains($body, 'No GP data') || str_contains($body, 'No results')) {
            return $this->success([]);
        }

        $lines   = array_values(array_filter(
            array_map('trim', explode("\n", $body)),
            fn ($l) => $l !== ''
        ));
        $results = [];

        for ($i = 0; $i + 2 < count($lines); $i += 3) {
            $line1 = $lines[$i + 1];
            $line2 = $lines[$i + 2];

            if (! str_starts_with($line1, '1 ') || ! str_starts_with($line2, '2 ')) {
                continue;
            }

            $results[] = [
                'norad_id' => trim(substr($line1, 2, 5)),
                'name'     => $lines[$i],
            ];

            if (count($results) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $this->success($results);
    }
}
