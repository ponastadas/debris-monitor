<?php

namespace App\Services;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP client for Space-Track.org.
 *
 * Space-Track uses session-cookie authentication:
 *   1. POST /ajaxauth/login with credentials → sets a session cookie.
 *   2. All subsequent requests must carry that cookie.
 *
 * We share a Guzzle CookieJar across the login and data requests so the
 * cookie is forwarded automatically.
 *
 * Credentials: SPACE_TRACK_USER / SPACE_TRACK_PASS in .env.
 * Free account available at https://www.space-track.org/auth/#/login
 */
class SpaceTrackClient
{
    private const BASE          = 'https://www.space-track.org';
    private const LOGIN_PATH    = '/ajaxauth/login';
    private const CDM_PATH      = '/basicspacedata/query/class/cdm_public'
                                . '/TCA/%3Enow-1'     // TCA > (now − 1 day)
                                . '/orderby/TCA%20asc'
                                . '/LIMIT/1000'
                                . '/format/json';

    private readonly CookieJar $jar;

    public function __construct()
    {
        $this->jar = new CookieJar();
    }

    /**
     * Authenticate with Space-Track.
     * Returns true when login succeeds (HTTP 200 and no "Login" redirect body).
     */
    public function login(string $user, string $pass): bool
    {
        try {
            $response = Http::withOptions(['cookies' => $this->jar])
                ->timeout(15)
                ->asForm()
                ->post(self::BASE . self::LOGIN_PATH, [
                    'identity' => $user,
                    'password' => $pass,
                ]);

            // Space-Track returns 200 even for bad credentials; the body
            // will contain a Login page or the string "Failed".
            if (! $response->ok()) {
                return false;
            }

            $body = $response->body();
            if (str_contains($body, 'Failed') || str_contains($body, 'login')) {
                Log::warning('[SpaceTrack] Login rejected by server.');
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('[SpaceTrack] Login exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Fetch CDM_PUBLIC data — conjunction events for the next 7 days.
     *
     * @return list<array<string, mixed>>  Raw CDM records from Space-Track.
     */
    public function fetchCdm(): array
    {
        try {
            $response = Http::withOptions(['cookies' => $this->jar])
                ->timeout(60)
                ->get(self::BASE . self::CDM_PATH);

            if (! $response->ok()) {
                Log::warning('[SpaceTrack] CDM fetch returned HTTP ' . $response->status());
                return [];
            }

            $data = $response->json();

            if (! is_array($data)) {
                Log::warning('[SpaceTrack] CDM response was not a JSON array.');
                return [];
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error('[SpaceTrack] CDM fetch exception: ' . $e->getMessage());
            return [];
        }
    }
}
