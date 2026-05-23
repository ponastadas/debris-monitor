<?php

namespace App\Services;

use GuzzleHttp\Client as GuzzleClient;
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
    private const BASE = 'https://www.space-track.org';

    private const LOGIN_PATH = '/ajaxauth/login';

    private const CDM_PATH = '/basicspacedata/query/class/cdm_public'
        .'/TCA/%3Enow-1'
        .'/orderby/TCA%20asc'
        .'/LIMIT/1000'
        .'/format/json';

    private const GP_BASE = '/basicspacedata/query/class/gp';

    private readonly CookieJar $jar;

    public function __construct()
    {
        $this->jar = new CookieJar;
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
                ->post(self::BASE.self::LOGIN_PATH, [
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
            Log::error('[SpaceTrack] Login exception: '.$e->getMessage());

            return false;
        }
    }

    private const GP_PAGE_SIZE = 2000;

    /**
     * Fetch all currently tracked GP objects of a given OBJECT_TYPE.
     *
     * Paginated in 2000-record pages to stay within PHP's 128M memory limit.
     * DECAY_DATE + EPOCH filters exclude re-entered objects and stale ephemerides,
     * per Space-Track GP usage guidelines (epoch/%3Enow-10/).
     *
     * Valid types: PAYLOAD | ROCKET BODY | DEBRIS | UNKNOWN | TBA
     *
     * @return list<array{norad_id: string, name: string, object_type: string, international_designator: string|null, line1: string, line2: string}>|null
     *                                                                                                                                                    null = network/HTTP error
     */
    public function fetchGpByType(string $type): ?array
    {
        $base = self::GP_BASE
            .'/OBJECT_TYPE/'.rawurlencode($type)
            .'/DECAY_DATE/null-val'
            .'/EPOCH/%3Enow-10';   // only propagable ephemerides (Space-Track guideline)

        return $this->fetchGpPaginated($base);
    }

    /**
     * Fetch GP objects whose TLE epoch is newer than $since (incremental sync).
     * Covers all object types; excludes decayed objects.
     *
     * @return list<array{norad_id: string, name: string, object_type: string, international_designator: string|null, line1: string, line2: string}>|null
     */
    public function fetchGpSince(\DateTimeInterface $since): ?array
    {
        $base = self::GP_BASE
            .'/EPOCH/%3E'.$since->format('Y-m-d')   // %3E = >
            .'/DECAY_DATE/null-val';

        return $this->fetchGpPaginated($base);
    }

    /**
     * Fetch GP records for a list of NORAD IDs in a single comma-delimited request.
     *
     * This avoids sending one request per satellite (forbidden by Space-Track policy).
     * Chunking is the caller's responsibility — pass at most ~500 IDs per call to stay
     * within safe URL-length limits.
     *
     * @param  list<string>  $noradIds  5-digit zero-padded NORAD IDs
     * @return list<array{norad_id: string, name: string, object_type: string, international_designator: string|null, line1: string, line2: string}>|null
     *                                                                                                                                                    null = network/HTTP error
     */
    public function fetchGpByNoradList(array $noradIds): ?array
    {
        if (empty($noradIds)) {
            return [];
        }

        // Strip leading zeros for the URL (Space-Track rejects zero-padded IDs in the path).
        $ids = implode(',', array_map(fn ($id) => ltrim($id, '0') ?: '0', $noradIds));
        $url = self::BASE.self::GP_BASE.'/NORAD_CAT_ID/'.$ids.'/format/json';

        $guzzle = new GuzzleClient(['cookies' => $this->jar, 'timeout' => 60]);

        try {
            $response = $guzzle->get($url);
        } catch (\Throwable $e) {
            Log::error('[SpaceTrack] GP batch fetch error: '.$e->getMessage());

            return null;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            Log::warning('[SpaceTrack] GP batch fetch HTTP '.$status.' — body: '.substr((string) $response->getBody(), 0, 300));

            return null;
        }

        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data)) {
            Log::warning('[SpaceTrack] GP batch response was not a JSON array');

            return null;
        }

        $results = [];
        foreach ($data as $r) {
            $record = $this->normalizeGpRecord($r);
            if ($record !== null) {
                $results[] = $record;
            }
        }

        return $results;
    }

    /**
     * Paginate through a GP query using NORAD_CAT_ID cursor pagination.
     *
     * Space-Track does not support /offset/N — instead we filter NORAD_CAT_ID > lastSeen
     * on each page. Stops when a page returns fewer records than GP_PAGE_SIZE.
     *
     * Uses Guzzle directly (not Laravel's Http facade) to ensure the shared
     * CookieJar from login() is correctly forwarded to each page request.
     */
    private function fetchGpPaginated(string $basePath): ?array
    {
        $guzzle = new GuzzleClient(['cookies' => $this->jar, 'timeout' => 60]);
        $results = [];
        $cursor = 0; // NORAD_CAT_ID > cursor (0 = fetch from the beginning)

        while (true) {
            $url = self::BASE.$basePath
                .'/NORAD_CAT_ID/%3E'.$cursor          // %3E = >
                .'/orderby/NORAD_CAT_ID%20asc'
                .'/limit/'.self::GP_PAGE_SIZE
                .'/format/json';

            try {
                $response = $guzzle->get($url);
            } catch (\Throwable $e) {
                Log::error('[SpaceTrack] GP fetch error: '.$e->getMessage());

                return null;
            }

            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                Log::warning('[SpaceTrack] GP fetch HTTP '.$statusCode.' — body: '.substr((string) $response->getBody(), 0, 300));

                return null;
            }

            $page = json_decode((string) $response->getBody(), true);

            if (! is_array($page)) {
                Log::warning('[SpaceTrack] GP response was not a JSON array');

                return null;
            }

            foreach ($page as $r) {
                $record = $this->normalizeGpRecord($r);
                if ($record !== null) {
                    $results[] = $record;
                }
            }

            if (count($page) < self::GP_PAGE_SIZE) {
                break; // last page
            }

            $cursor = (int) end($page)['NORAD_CAT_ID'];
            usleep(300_000); // 300ms between pages — avoid Space-Track rate limiting
        }

        return $results;
    }

    /**
     * @return array{norad_id: string, name: string, object_type: string, international_designator: string|null, line1: string, line2: string}|null
     */
    private function normalizeGpRecord(array $r): ?array
    {
        $noradId = trim((string) ($r['NORAD_CAT_ID'] ?? ''));
        $line1 = trim($r['TLE_LINE1'] ?? '');
        $line2 = trim($r['TLE_LINE2'] ?? '');

        if (! $noradId || ! $line1 || ! $line2) {
            return null;
        }

        if (! str_starts_with($line1, '1 ') || ! str_starts_with($line2, '2 ')) {
            return null;
        }

        $designator = trim($r['OBJECT_ID'] ?? '');

        return [
            'norad_id' => str_pad($noradId, 5, '0', STR_PAD_LEFT),
            'name' => trim($r['OBJECT_NAME'] ?? $noradId),
            'object_type' => $r['OBJECT_TYPE'] ?? null,
            'international_designator' => ($designator && $designator !== 'TBA') ? $designator : null,
            'line1' => $line1,
            'line2' => $line2,
        ];
    }

    /**
     * Fetch a single GP record by NORAD ID.
     * Used by the staleness sweep to refresh individual satellites.
     *
     * @return array{norad_id: string, name: string, object_type: string, international_designator: string|null, line1: string, line2: string}|null
     */
    public function fetchGpByNorad(string $noradId): ?array
    {
        $id = ltrim($noradId, '0') ?: '0';
        $url = self::BASE.self::GP_BASE.'/NORAD_CAT_ID/'.rawurlencode($id).'/format/json';

        $guzzle = new GuzzleClient(['cookies' => $this->jar, 'timeout' => 15]);

        try {
            $response = $guzzle->get($url);
        } catch (\Throwable $e) {
            Log::error('[SpaceTrack] GP single fetch error: '.$e->getMessage());

            return null;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return null;
        }

        $data = json_decode((string) $response->getBody(), true);

        if (! is_array($data) || count($data) === 0) {
            return null;
        }

        return $this->normalizeGpRecord($data[0]);
    }

    /**
     * Fetch CDM_PUBLIC data — conjunction events for the next 7 days.
     *
     * @return list<array<string, mixed>> Raw CDM records from Space-Track.
     */
    public function fetchCdm(): array
    {
        try {
            $response = Http::withOptions(['cookies' => $this->jar])
                ->timeout(60)
                ->get(self::BASE.self::CDM_PATH);

            if (! $response->ok()) {
                Log::warning('[SpaceTrack] CDM fetch returned HTTP '.$response->status());

                return [];
            }

            $data = $response->json();

            if (! is_array($data)) {
                Log::warning('[SpaceTrack] CDM response was not a JSON array.');

                return [];
            }

            return $data;
        } catch (\Throwable $e) {
            Log::error('[SpaceTrack] CDM fetch exception: '.$e->getMessage());

            return [];
        }
    }
}
