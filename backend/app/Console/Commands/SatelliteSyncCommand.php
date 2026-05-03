<?php

namespace App\Console\Commands;

use App\Models\Satellite;
use App\Models\TleRecord;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SatelliteSyncCommand extends Command
{
    protected $signature = 'satellites:sync
                            {--groups= : Comma-separated CelesTrak group names (default: all configured groups)}
                            {--dry-run : Parse and count without writing to DB}';

    protected $description = 'Fetch TLE data from CelesTrak and upsert into local satellite catalog';

    /**
     * CelesTrak TLE groups to sync.
     * Covers active satellites, major debris fields, and rocket bodies.
     * Add groups here when the catalog needs expanding.
     */
    private const DEFAULT_GROUPS = [
        // Full active catalog — broadest single-call coverage (~15K sats).
        // Rate-limited to one download per 2h per IP by CelesTrak; when
        // rate-limited it returns 403 and the group is skipped.  The
        // granular groups below act as a fallback that fills the catalog
        // even when 'active' is unavailable.
        'active',
        // Granular satellite categories — redundant with 'active' when that
        // succeeds, but ensure ~4K+ objects are always synced regardless.
        'stations',
        'weather',
        'noaa',
        'goes',
        'resource',
        'sarsat',
        'dmc',
        'tdrss',
        'argos',
        'planet',
        'spire',
        'oneweb',
        'starlink',
        'iridium-NEXT',
        'geo',
        'last-30-days',
        'gps-ops',
        'glo-ops',
        'galileo',
        'beidou',
        'sbas',
        'amateur',
        'cubesat',
        // Debris fields — after satellite groups so object_type is set correctly
        'fengyun-1c-debris',
        'cosmos-2251-debris',
        'iridium-33-debris',
    ];

    private const CELESTRAK_URL = 'https://celestrak.org/NORAD/elements/gp.php';

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
        'rocket-bodies'      => 'rocket_body',
    ];

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $groups   = $this->option('groups')
            ? explode(',', $this->option('groups'))
            : self::DEFAULT_GROUPS;

        $this->info('Debris Monitor — Satellite Catalog Sync');
        $this->line('Groups: '.implode(', ', $groups));
        $isDryRun && $this->warn('DRY RUN — no data will be written');
        $this->newLine();

        $totalSatellites = 0;
        $totalTleRecords = 0;
        $now             = now();

        foreach ($groups as $group) {
            $group = trim($group);
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

            $objectType = self::OBJECT_TYPE_MAP[$group] ?? null;
            [$upserted, $tleInserted] = $this->persistBatch($parsed, $objectType, $now);

            $this->line("  ✓ Upserted {$upserted} satellites, {$tleInserted} TLE records");
            $totalSatellites += $upserted;
            $totalTleRecords += $tleInserted;
        }

        $this->newLine();
        $this->info("Sync complete — {$totalSatellites} satellites, {$totalTleRecords} TLE records");

        // Staleness sweep: refresh any satellite whose current TLE is older than
        // 24h, regardless of which group it came from.  This keeps on-demand-cached
        // satellites (e.g. HORACIO, R2) from rotting between full syncs.
        $this->runStalenessSweep($isDryRun);

        return self::SUCCESS;
    }

    /**
     * Refresh stale per-satellite TLEs via individual CATNR= calls.
     *
     * Candidates: current TLE older than 24h, OR no current TLE at all.
     * Ordered oldest-first so the most-stale are always prioritised.
     * Capped at SATELLITE_SYNC_STALE_LIMIT (default 200) per run.
     */
    private function runStalenessSweep(bool $isDryRun): void
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

        foreach ($candidates as $sat) {
            $result = $this->fetchSingleTle($sat->norad_id);

            if ($result === false) {
                // 429 — CelesTrak is throttling individual CATNR calls; abort sweep.
                $this->warn("  Staleness sweep stopped: CelesTrak returned 429 — will retry on next run");
                break;
            }

            if ($result === null) {
                $this->line("  ✗ Could not refresh NORAD {$sat->norad_id} — skipping");
                usleep(100_000);
                continue;
            }

            $sat->upsertCurrentTle($result['line1'], $result['line2']);
            $refreshed++;

            usleep(100_000); // 100ms between calls — respect CelesTrak rate limits
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
     * Fetch a CelesTrak group and return parsed TLE triplets.
     * Returns null on HTTP failure, empty array when group has no results.
     *
     * @return list<array{name: string, norad_id: string, line1: string, line2: string}>|null
     */
    private function fetchGroup(string $group): ?array
    {
        try {
            $response = Http::timeout(30)->get(self::CELESTRAK_URL, [
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
     * Returns [satellites upserted, TLE records inserted].
     *
     * @param list<array{name: string, norad_id: string, line1: string, line2: string}> $parsed
     */
    private function persistBatch(array $parsed, ?string $objectType, Carbon $now): array
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
        $satelliteRows = array_map(fn ($p) => [
            'norad_id'                => $p['norad_id'],
            'name'                    => $p['name'],
            'object_type'             => $objectType,
            'international_designator' => $p['international_designator'] ?? null,
            'is_active'               => $objectType !== 'debris' && $objectType !== 'rocket_body',
            'catalog_source'          => 'celestrak',
            'last_seen_at'            => $now->toDateTimeString(),
            'created_at'              => $now->toDateTimeString(),
            'updated_at'              => $now->toDateTimeString(),
        ], $parsed);

        foreach (array_chunk($satelliteRows, 7000) as $chunk) {
            DB::table('satellites')->upsert(
                $chunk,
                ['norad_id'],
                ['name', 'object_type', 'international_designator', 'is_active', 'catalog_source', 'last_seen_at', 'updated_at']
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
                'source'       => 'celestrak',
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
