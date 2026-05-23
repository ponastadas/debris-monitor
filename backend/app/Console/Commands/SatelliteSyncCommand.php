<?php

namespace App\Console\Commands;

use App\Models\Satellite;
use App\Models\TleRecord;
use App\Services\SpaceTrackClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SatelliteSyncCommand extends Command
{
    protected $signature = 'satellites:sync
                            {--source=spacetrack : Data source: spacetrack (default) or celestrak}
                            {--groups= : Comma-separated CelesTrak group names — only used when --source=celestrak}
                            {--incremental : Only fetch recently updated objects + staleness sweep; skips full catalog re-download}
                            {--dry-run : Parse and count without writing to DB}';

    protected $description = 'Sync satellite catalog from Space-Track (default) or CelesTrak';

    /**
     * CelesTrak TLE groups for a full catalog sync (6 requests, non-overlapping).
     *
     * 'active' already contains every object in the granular groups (weather,
     * noaa, starlink, stations, etc.) — listing them separately would just
     * repeat 20+ round-trips for no extra coverage.  Only groups that are
     * genuinely disjoint from 'active' are listed here.
     */
    private const DEFAULT_GROUPS = [
        'active',              // all active payloads (~10K-15K objects)
        // rocket-bodies group was removed by CelesTrak; rocket bodies in SATCAT get
        // classified via satcatTypes (R/B → rocket_body) when they appear in other groups
        'fengyun-1c-debris',
        'cosmos-2251-debris',
        'iridium-33-debris',
        '2019-006',            // ASAT test debris field
    ];

    /**
     * Groups used in incremental mode: only objects catalogued in the last 30 days.
     * Combined with the staleness sweep this keeps existing TLEs fresh without a
     * full re-download.
     */
    private const INCREMENTAL_GROUPS = [
        'last-30-days',
    ];

    /**
     * Space-Track OBJECT_TYPE values fetched during a full sync.
     * UNKNOWN and TBA are omitted — they have no useful TLE data.
     */
    private const SPACE_TRACK_TYPES = [
        'PAYLOAD',
        'ROCKET BODY',
        'DEBRIS',
    ];

    /** Maps Space-Track OBJECT_TYPE → our internal object_type. */
    private const SPACE_TRACK_TYPE_MAP = [
        'PAYLOAD'      => 'satellite',
        'ROCKET BODY'  => 'rocket_body',
        'DEBRIS'       => 'debris',
        'UNKNOWN'      => null,
        'TBA'          => null,
    ];

    private const CELESTRAK_URL = 'https://celestrak.org/NORAD/elements/gp.php';

    private const SATCAT_URL = 'https://celestrak.org/pub/satcat.csv';

    /**
     * SATCAT OBJECT_TYPE column value → satellites.object_type.
     * PAY = payload (active satellite), R/B = rocket body, DEB = debris,
     * UNK/TBA = unknown classification.  null means store as NULL in the DB.
     */
    private const SATCAT_TYPE_MAP = [
        'PAY' => 'satellite',
        'R/B' => 'rocket_body',
        'DEB' => 'debris',
        'UNK' => null,
        'TBA' => null,
    ];

    private const OBJECT_TYPE_MAP = [
        'active'             => 'satellite',
        'stations'           => 'satellite',
        'weather'            => 'satellite',
        'noaa'               => 'satellite',
        'goes'               => 'satellite',
        'resource'           => 'satellite',
        'sarsat'             => 'satellite',
        'dmc'                => 'satellite',
        'tdrss'              => 'satellite',
        'argos'              => 'satellite',
        'planet'             => 'satellite',
        'spire'              => 'satellite',
        'oneweb'             => 'satellite',
        'starlink'           => 'satellite',
        'iridium-NEXT'       => 'satellite',
        'geo'                => 'satellite',
        'last-30-days'       => 'satellite',
        'gps-ops'            => 'satellite',
        'glo-ops'            => 'satellite',
        'galileo'            => 'satellite',
        'beidou'             => 'satellite',
        'sbas'               => 'satellite',
        'amateur'            => 'satellite',
        'cubesat'            => 'satellite',
        'fengyun-1c-debris'  => 'debris',
        'cosmos-2251-debris' => 'debris',
        'iridium-33-debris'  => 'debris',
        '2019-006'           => 'debris',
    ];

    public function handle(): int
    {
        $isDryRun      = $this->option('dry-run');
        $isIncremental = $this->option('incremental');

        if ($this->option('source') !== 'celestrak') {
            return $this->handleSpaceTrack($isDryRun, $isIncremental);
        }

        return $this->handleCelesTrak($isDryRun, $isIncremental);
    }

    private function handleCelesTrak(bool $isDryRun, bool $isIncremental): int
    {
        if ($this->option('groups')) {
            $groups = explode(',', $this->option('groups'));
        } elseif ($isIncremental) {
            $groups = self::INCREMENTAL_GROUPS;
        } else {
            $groups = self::DEFAULT_GROUPS;
        }

        $this->info('SatView — Satellite Catalog Sync (CelesTrak)' . ($isIncremental ? ' (incremental)' : ''));
        $this->line('Groups: '.implode(', ', $groups));
        $isDryRun && $this->warn('DRY RUN — no data will be written');
        $this->newLine();

        // SATCAT per-object classification is only needed on full syncs; incremental
        // runs only add new objects whose type will already be set by group-level map.
        $satcatTypes = $isIncremental ? [] : $this->fetchSatcatTypes();
        if (! $isIncremental) {
            $this->newLine();
        }

        $totalSatellites = 0;
        $totalTleRecords = 0;
        $now             = now();
        $first           = true;

        foreach ($groups as $group) {
            $group = trim($group);

            if (! $first) {
                usleep(500_000); // 500ms between requests — respect CelesTrak rate limits
            }
            $first = false;

            $this->line("Fetching group: <comment>{$group}</comment>");

            $parsed = $this->fetchGroup($group);

            if ($parsed === null) {
                $this->warn("  ✗ Failed to fetch — skipping");
                continue;
            }

            $count = count($parsed);
            $this->line("  Parsed {$count} satellites");

            if ($isDryRun || $count === 0) {
                $totalSatellites += $count;
                continue;
            }

            // Annotate each object with its per-object type from SATCAT,
            // falling back to the group-level type when SATCAT has no entry.
            $groupType = self::OBJECT_TYPE_MAP[$group] ?? null;
            foreach ($parsed as &$p) {
                $p['object_type'] = array_key_exists($p['norad_id'], $satcatTypes)
                    ? $satcatTypes[$p['norad_id']]
                    : $groupType;
            }
            unset($p);

            [$upserted, $tleInserted] = $this->persistBatch($parsed, $now);

            $this->line("  ✓ Upserted {$upserted} satellites, {$tleInserted} TLE records");
            $totalSatellites += $upserted;
            $totalTleRecords += $tleInserted;
        }

        $this->newLine();

        // Report the true unique count from the DB — the per-group tallies above
        // are inflated because many CelesTrak groups overlap (e.g. starlink ⊂ active).
        $uniqueCount = DB::table('tle_records')->where('is_current', true)->count();
        $this->info("Sync complete — {$uniqueCount} unique objects with current TLE ({$totalSatellites} records parsed across ".count($groups)." groups, {$totalTleRecords} TLE records inserted)");

        // Staleness sweep: refresh any satellite whose current TLE is older than
        // 24h, regardless of which group it came from.  This keeps on-demand-cached
        // satellites (e.g. HORACIO, R2) from rotting between full syncs.
        $this->runStalenessSweep($isDryRun);

        return self::SUCCESS;
    }

    private function handleSpaceTrack(bool $isDryRun, bool $isIncremental): int
    {
        $user = config('services.space_track.user');
        $pass = config('services.space_track.pass');

        if (! $user || ! $pass) {
            $this->warn('SPACE_TRACK_USER / SPACE_TRACK_PASS not set — falling back to CelesTrak.');
            return $this->handleCelesTrak($isDryRun, $isIncremental);
        }

        $client = new SpaceTrackClient();

        $this->info('SatView — Satellite Catalog Sync (Space-Track)' . ($isIncremental ? ' (incremental)' : ''));
        $this->line('Logging in to Space-Track.org…');

        if (! $client->login($user, $pass)) {
            $this->error('Login failed — check SPACE_TRACK_USER / SPACE_TRACK_PASS in .env');
            return self::FAILURE;
        }

        $this->line('  Authenticated');
        $this->newLine();

        $now   = now();
        $total = 0;
        $tles  = 0;

        if ($isIncremental) {
            $since = now()->subDay();
            $this->line("Fetching GP updates since <comment>{$since->toDateString()}</comment>…");

            $records = $client->fetchGpSince($since);

            if ($records === null) {
                $this->error('Failed to fetch incremental GP data from Space-Track');
                return self::FAILURE;
            }

            $count = count($records);
            $this->line("  Received {$count} updated objects");

            if (! $isDryRun && $count > 0) {
                $this->applySpaceTrackTypeMap($records);
                [$upserted, $tleInserted] = $this->persistBatch($records, $now, 'spacetrack');
                $this->line("  ✓ Upserted {$upserted} satellites, {$tleInserted} TLE records");
                $total += $upserted;
                $tles  += $tleInserted;
            } else {
                $total += $count;
            }
        } else {
            foreach (self::SPACE_TRACK_TYPES as $type) {
                $this->line("Fetching type: <comment>{$type}</comment>…");

                $records = $client->fetchGpByType($type);

                if ($records === null) {
                    $this->warn("  ✗ Failed to fetch — skipping");
                    continue;
                }

                $count = count($records);
                $this->line("  Received {$count} objects");

                if ($isDryRun || $count === 0) {
                    $total += $count;
                    continue;
                }

                $this->applySpaceTrackTypeMap($records);
                [$upserted, $tleInserted] = $this->persistBatch($records, $now, 'spacetrack');
                $this->line("  ✓ Upserted {$upserted} satellites, {$tleInserted} TLE records");
                $total += $upserted;
                $tles  += $tleInserted;

                usleep(500_000); // 500ms between type queries
            }
        }

        $this->newLine();
        $uniqueCount = DB::table('tle_records')->where('is_current', true)->count();
        $this->info("Sync complete — {$uniqueCount} unique objects with current TLE ({$total} parsed, {$tles} TLE records inserted)");

        $this->runStalenessSweep($isDryRun, $client);

        return self::SUCCESS;
    }

    private function applySpaceTrackTypeMap(array &$records): void
    {
        foreach ($records as &$r) {
            $r['object_type'] = self::SPACE_TRACK_TYPE_MAP[$r['object_type'] ?? ''] ?? null;
        }
        unset($r);
    }

    /**
     * Refresh stale per-satellite TLEs.
     *
     * When an authenticated $client is provided (Space-Track mode) each satellite
     * is refreshed via the GP single-object endpoint.  Without a client it falls
     * back to the CelesTrak CATNR= endpoint.
     *
     * Candidates: current TLE older than 24h, OR no current TLE at all.
     * Ordered oldest-first so the most-stale are always prioritised.
     * Capped at SATELLITE_SYNC_STALE_LIMIT (default 200) per run.
     */
    private function runStalenessSweep(bool $isDryRun, ?SpaceTrackClient $client = null): void
    {
        $limit     = (int) env('SATELLITE_SYNC_STALE_LIMIT', 200);
        $threshold = now()->subHours(24);

        // Left-join to tle_records so we can filter on fetched_at and order by it,
        // including satellites that have no current TLE (fetched_at IS NULL).
        $candidates = Satellite::select('satellites.*')
            ->leftJoin('tle_records', function ($join) {
                $join->on('tle_records.satellite_id', '=', 'satellites.id')
                     ->where('tle_records.is_current', true);
            })
            ->where(function ($q) use ($threshold) {
                $q->whereNull('tle_records.id')
                  ->orWhere('tle_records.fetched_at', '<', $threshold);
            })
            ->orderByRaw('COALESCE(tle_records.fetched_at, 0) ASC')
            ->limit($limit + 1)   // fetch one extra to detect the cap
            ->get();

        $total = $candidates->count();

        if ($total === 0) {
            $this->line('Staleness sweep: all TLEs are fresh — nothing to do');
            return;
        }

        $capped = $total > $limit;
        $candidates = $candidates->take($limit);

        $this->newLine();
        $this->line("Staleness sweep: <comment>{$candidates->count()}</comment> stale satellite(s)" . ($capped ? " (cap {$limit} hit — more remain)" : ''));
        $capped && $this->warn("  Limit of {$limit} reached; remaining satellites will be swept on the next run");

        if ($isDryRun) {
            foreach ($candidates as $sat) {
                $this->line("  [dry-run] would refresh NORAD {$sat->norad_id} ({$sat->name})");
            }
            return;
        }

        $refreshed = 0;

        if ($client !== null) {
            // Space-Track mode: fetch all stale satellites in bulk (comma-delimited list)
            // to comply with the policy against individual per-satellite requests.
            // Chunk into batches of 500 to stay within safe URL-length limits.
            $noradIds  = $candidates->pluck('norad_id')->all();
            $satByNorad = $candidates->keyBy('norad_id');

            foreach (array_chunk($noradIds, 500) as $chunk) {
                $records = $client->fetchGpByNoradList($chunk);

                if ($records === null) {
                    $this->warn("  Staleness sweep: batch fetch failed — skipping this chunk");
                    continue;
                }

                foreach ($records as $record) {
                    $sat = $satByNorad[$record['norad_id']] ?? null;
                    if (! $sat) {
                        continue;
                    }
                    $sat->upsertCurrentTle($record['line1'], $record['line2']);
                    $refreshed++;
                }

                if (count($noradIds) > 500) {
                    usleep(300_000); // 300ms between chunks when multiple requests needed
                }
            }
        } else {
            foreach ($candidates as $sat) {
                $result = $this->fetchSingleTle($sat->norad_id);

                if ($result === false) {
                    $this->warn("  Staleness sweep stopped: CelesTrak returned 429 — will retry on next run");
                    break;
                }

                usleep(100_000); // 100ms between CelesTrak calls

                if ($result === null) {
                    $this->line("  ✗ Could not refresh NORAD {$sat->norad_id} — skipping");
                    continue;
                }

                $sat->upsertCurrentTle($result['line1'], $result['line2']);
                $refreshed++;
            }
        }

        $this->line("Staleness sweep complete — {$refreshed} TLE(s) refreshed");
    }

    /**
     * Fetch TLE for a single satellite by NORAD ID (CATNR= endpoint).
     *
     * Returns:
     *   array  — parsed TLE on success
     *   null   — satellite not found or non-throttle error (skip, continue)
     *   false  — HTTP 429 received (stop sweep for this run)
     *
     * @return array{line1: string, line2: string}|null|false
     */
    private function fetchSingleTle(string $noradId): array|null|false
    {
        try {
            $response = Http::timeout(10)->get(self::CELESTRAK_URL, [
                'CATNR'  => $noradId,
                'FORMAT' => 'TLE',
            ]);
        } catch (\Throwable $e) {
            $this->warn("  HTTP error for NORAD {$noradId}: {$e->getMessage()}");
            return null;
        }

        if ($response->status() === 429) {
            return false;
        }

        if (! $response->ok()) {
            return null;
        }

        $body = trim($response->body());

        if (! $body || str_contains($body, 'No GP data')) {
            return null;
        }

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $body)),
            fn ($l) => $l !== ''
        ));

        if (count($lines) < 3 || ! str_starts_with($lines[1], '1 ') || ! str_starts_with($lines[2], '2 ')) {
            return null;
        }

        return ['line1' => $lines[1], 'line2' => $lines[2]];
    }

    /**
     * Fetch SATCAT CSV and build a NORAD ID → object_type map.
     *
     * SATCAT provides authoritative per-object classification (PAY/R/B/DEB/UNK),
     * whereas CelesTrak TLE groups only expose group-level type hints that
     * misclassify rocket bodies and debris mixed into the 'active' group.
     *
     * Returns an empty array on network failure; callers fall back to group-level types.
     *
     * @return array<string, string|null>  norad_id (5-digit zero-padded) => object_type|null
     */
    private function fetchSatcatTypes(): array
    {
        $this->line('Fetching SATCAT for per-object type classification…');

        try {
            $response = Http::timeout(120)->get(self::SATCAT_URL);
        } catch (\Throwable $e) {
            $this->warn("SATCAT fetch failed: {$e->getMessage()} — falling back to group-level types");
            return [];
        }

        if (! $response->ok()) {
            $this->warn("SATCAT fetch failed: HTTP {$response->status()} — falling back to group-level types");
            return [];
        }

        $map   = [];
        $first = true;

        foreach (explode("\n", $response->body()) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($first) {
                $first = false; // skip header row
                continue;
            }

            $cols = str_getcsv($line);

            // NORAD_CAT_ID = column 2, OBJECT_TYPE = column 3
            if (count($cols) < 4) {
                continue;
            }

            $rawId = trim($cols[2]);
            if (! $rawId || ! ctype_digit($rawId)) {
                continue;
            }

            // Normalise to 5-digit zero-padded string to match TLE-parsed norad_id values.
            $noradId    = str_pad($rawId, 5, '0', STR_PAD_LEFT);
            $satcatType = trim($cols[3]);

            if (array_key_exists($satcatType, self::SATCAT_TYPE_MAP)) {
                $map[$noradId] = self::SATCAT_TYPE_MAP[$satcatType];
            }
        }

        $this->line('  SATCAT loaded — '.count($map).' object type mappings');

        return $map;
    }

    /**
     * Fetch a CelesTrak group and return parsed TLE triplets.
     * Returns null on HTTP failure, empty array when group has no results.
     *
     * @return list<array{name: string, norad_id: string, line1: string, line2: string}>|null
     */
    private function fetchGroup(string $group): ?array
    {
        try {
            $response = Http::timeout(90)->get(self::CELESTRAK_URL, [
                'GROUP'  => $group,
                'FORMAT' => 'TLE',
            ]);
        } catch (\Throwable $e) {
            $this->warn("  HTTP error: {$e->getMessage()}");
            return null;
        }

        if (! $response->ok()) {
            $body = trim($response->body());
            if ($response->status() === 403 && str_contains($body, 'not updated since your last')) {
                $this->warn("  Rate-limited by CelesTrak (data unchanged since last download — retry in 2h)");
            } else {
                $this->warn("  HTTP {$response->status()}");
            }
            return null;
        }

        $body = trim($response->body());

        if (! $body || str_contains($body, 'No GP data') || str_contains($body, 'No results')) {
            return [];
        }

        return $this->parseTleBody($body);
    }

    /**
     * Parse raw TLE body (3-line sets) into structured records.
     *
     * @return list<array{name: string, norad_id: string, line1: string, line2: string}>
     */
    private function parseTleBody(string $body): array
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $body)),
            fn ($l) => $l !== ''
        ));

        $results = [];

        for ($i = 0; $i + 2 < count($lines); $i += 3) {
            $name  = $lines[$i];
            $line1 = $lines[$i + 1];
            $line2 = $lines[$i + 2];

            if (! str_starts_with($line1, '1 ') || ! str_starts_with($line2, '2 ')) {
                continue;
            }

            $noradId = trim(substr($line1, 2, 5));

            if (! $noradId || ! ctype_digit($noradId)) {
                continue;
            }

            $results[] = [
                'name'                    => $name,
                'norad_id'                => $noradId,
                'line1'                   => $line1,
                'line2'                   => $line2,
                'international_designator' => $this->parseDesignator($line1),
            ];
        }

        return $results;
    }

    /**
     * Upsert a batch of parsed TLE records into the DB.
     * Each element of $parsed must have an 'object_type' key (nullable string).
     * When object_type is null the existing DB value is preserved (incremental updates).
     * Returns [satellites upserted, TLE records inserted].
     *
     * @param list<array{name: string, norad_id: string, line1: string, line2: string, object_type: string|null}> $parsed
     */
    private function persistBatch(array $parsed, Carbon $now, string $source = 'celestrak'): array
    {
        // Deduplicate by norad_id within the batch — a CelesTrak group file can
        // occasionally contain the same satellite twice (e.g. active group overlaps
        // with stations). MySQL's ON DUPLICATE KEY UPDATE rejects two rows with the
        // same unique key in a single INSERT statement.
        $seen   = [];
        $unique = [];
        foreach ($parsed as $p) {
            if (! isset($seen[$p['norad_id']])) {
                $seen[$p['norad_id']] = true;
                $unique[]             = $p;
            }
        }
        $parsed = $unique;

        // Step 1: Upsert satellites (norad_id is the unique key).
        // Chunked to stay under MySQL's 65,535 prepared-statement placeholder limit
        // (9 columns × 7,000 rows = 63,000 placeholders per batch).
        // When object_type is null (e.g. incremental fetch with mixed types), skip updating
        // it so the existing classification is preserved.
        $withType    = array_filter($parsed, fn ($p) => ($p['object_type'] ?? null) !== null);
        $withoutType = array_filter($parsed, fn ($p) => ($p['object_type'] ?? null) === null);

        $makeRow = function (array $p) use ($now, $source): array {
            $type = $p['object_type'] ?? null;
            return [
                'norad_id'                => $p['norad_id'],
                'name'                    => $p['name'],
                'object_type'             => $type,
                'international_designator' => $p['international_designator'] ?? null,
                'is_active'               => $type !== 'debris' && $type !== 'rocket_body',
                'catalog_source'          => $source,
                'last_seen_at'            => $now->toDateTimeString(),
                'created_at'              => $now->toDateTimeString(),
                'updated_at'              => $now->toDateTimeString(),
            ];
        };

        foreach (array_chunk(array_values($withType), 7000) as $chunk) {
            DB::table('satellites')->upsert(
                array_map($makeRow, $chunk),
                ['norad_id'],
                ['name', 'object_type', 'international_designator', 'is_active', 'catalog_source', 'last_seen_at', 'updated_at']
            );
        }

        foreach (array_chunk(array_values($withoutType), 7000) as $chunk) {
            DB::table('satellites')->upsert(
                array_map($makeRow, $chunk),
                ['norad_id'],
                ['name', 'international_designator', 'is_active', 'catalog_source', 'last_seen_at', 'updated_at']
                // object_type intentionally omitted — preserve existing DB classification
            );
        }

        // Step 2: Fetch satellite IDs we just upserted
        $noradIds    = array_column($parsed, 'norad_id');
        $satelliteMap = Satellite::whereIn('norad_id', $noradIds)
            ->pluck('id', 'norad_id');

        if ($satelliteMap->isEmpty()) {
            return [count($parsed), 0];
        }

        // Step 3: Mark existing current TLEs as not current for these satellites
        TleRecord::whereIn('satellite_id', $satelliteMap->values())
            ->where('is_current', true)
            ->update(['is_current' => false, 'updated_at' => $now]);

        // Step 4: Insert new current TLE records
        $tleRows = [];

        foreach ($parsed as $p) {
            $satelliteId = $satelliteMap[$p['norad_id']] ?? null;
            if (! $satelliteId) {
                continue;
            }

            $tleRows[] = [
                'satellite_id' => $satelliteId,
                'line1'        => $p['line1'],
                'line2'        => $p['line2'],
                'epoch_at'     => $this->parseEpoch($p['line1']),
                'source'       => $source,
                'fetched_at'   => $now->toDateTimeString(),
                'is_current'   => true,
                'created_at'   => $now->toDateTimeString(),
                'updated_at'   => $now->toDateTimeString(),
            ];
        }

        // 9 columns × 7,000 rows = 63,000 placeholders — stays under MySQL's 65,535 limit
        foreach (array_chunk($tleRows, 7000) as $chunk) {
            DB::table('tle_records')->insert($chunk);
        }

        return [count($parsed), count($tleRows)];
    }

    /**
     * Parse international designator from TLE line 1 (columns 9–16, 0-indexed).
     * Raw format: "98067A  " → normalised to "1998-067A".
     * Returns null if the field is blank or unrecognisable.
     */
    private function parseDesignator(string $line1): ?string
    {
        $raw = trim(substr($line1, 9, 8)); // e.g. "98067A" or "24001A  "

        if (! $raw || strlen($raw) < 5) {
            return null;
        }

        // Columns 9-10: 2-digit launch year; 11-13: launch number; 14-16: piece
        $year2  = substr($raw, 0, 2);
        $launch = substr($raw, 2, 3);
        $piece  = ltrim(substr($raw, 5), ' ') ?: null;

        if (! ctype_digit($year2) || ! ctype_digit($launch)) {
            return null;
        }

        $year4 = ((int) $year2 >= 57 ? 1900 : 2000) + (int) $year2;
        $desig = "{$year4}-{$launch}";

        if ($piece) {
            $desig .= strtoupper($piece);
        }

        return $desig;
    }

    /**
     * Parse TLE epoch from line 1 (columns 19–32).
     * Returns null if parsing fails.
     */
    private function parseEpoch(string $line1): ?string
    {
        try {
            $epochStr = trim(substr($line1, 18, 14));
            if (! $epochStr || strlen($epochStr) < 3) {
                return null;
            }

            $year2   = (int) substr($epochStr, 0, 2);
            $dayFrac = (float) substr($epochStr, 2);

            $year = $year2 >= 57 ? 1900 + $year2 : 2000 + $year2;

            return Carbon::create($year, 1, 1, 0, 0, 0, 'UTC')
                ->addDays($dayFrac - 1)
                ->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
