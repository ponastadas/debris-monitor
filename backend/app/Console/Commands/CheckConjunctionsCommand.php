<?php

namespace App\Console\Commands;

use App\Models\ConjunctionAlert;
use App\Models\User;
use App\Models\WatchedSatellite;
use App\Notifications\ConjunctionAlertNotification;
use App\Services\TlePropagator;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckConjunctionsCommand extends Command
{
    protected $signature   = 'conjunctions:check
                                {--dry-run : Detect conjunctions but skip notifications and DB writes}';

    protected $description = 'Screen watched satellites for conjunction threats in the next 5 days';

    /**
     * CelesTrak groups used as the threat catalog.
     * Covers the largest debris fields and active satellites.
     */
    private const DEBRIS_GROUPS = [
        'fengyun-1c-debris',
        'cosmos-2251-debris',
        'iridium-33-debris',
        '2019-006',      // ASAT test debris
        'rocket-bodies',
    ];

    private const CELESTRAK_TLE_URL   = 'https://celestrak.org/NORAD/elements/gp.php';
    private const CELESTRAK_GROUP_URL  = 'https://celestrak.org/NORAD/elements/gp.php';
    private const NOTIFY_HORIZON_DAYS  = 5;

    public function handle(TlePropagator $propagator): int
    {
        $isDryRun = $this->option('dry-run');

        // ── 1. Collect all watched NORAD IDs across all users ─────────────
        $watched = WatchedSatellite::with('user')->get();

        if ($watched->isEmpty()) {
            $this->info('No satellites being watched. Nothing to do.');
            return self::SUCCESS;
        }

        $this->info("Checking {$watched->count()} watched satellite(s) against debris catalog…");

        // ── 2. Refresh stale TLEs for watched satellites ──────────────────
        foreach ($watched as $sat) {
            if ($sat->hasFreshTle()) {
                continue;
            }
            $tle = $this->fetchTle($sat->norad_id);
            if ($tle === null) {
                $this->warn("  Could not fetch TLE for NORAD {$sat->norad_id} ({$sat->name})");
                continue;
            }
            if (! $isDryRun) {
                $sat->update([
                    'name'          => $tle['name'],
                    'tle_line1'     => $tle['line1'],
                    'tle_line2'     => $tle['line2'],
                    'tle_fetched_at' => now(),
                ]);
            }
            $sat->name     = $tle['name'];
            $sat->tle_line1 = $tle['line1'];
            $sat->tle_line2 = $tle['line2'];
        }

        // ── 3. Fetch debris catalog ───────────────────────────────────────
        $debrisCatalog = $this->fetchDebrisCatalog();
        $this->info('  Debris catalog: ' . count($debrisCatalog) . ' objects loaded.');

        if (empty($debrisCatalog)) {
            $this->error('Debris catalog empty — aborting.');
            return self::FAILURE;
        }

        // ── 4. Screen each watched satellite against every debris object ───
        $windowStart = new DateTime();
        $newAlerts   = 0;

        $bar = $this->output->createProgressBar($watched->count());
        $bar->start();

        foreach ($watched as $sat) {
            if (! $sat->tle_line1 || ! $sat->tle_line2) {
                $bar->advance();
                continue;
            }

            foreach ($debrisCatalog as $debris) {
                $result = $propagator->findClosestApproach(
                    $sat->tle_line1, $sat->tle_line2,
                    $debris['line1'], $debris['line2'],
                    $windowStart,
                    self::NOTIFY_HORIZON_DAYS,
                );

                if ($result === null) {
                    continue;
                }

                // Round TCA to the nearest minute to allow deduplication
                $tcaRounded = (clone $result['tca'])->setTime(
                    (int) $result['tca']->format('H'),
                    (int) $result['tca']->format('i'),
                    0
                );

                // Skip if we already have an alert for this pair within ±1 hour
                $exists = ConjunctionAlert::where('primary_norad_id', $sat->norad_id)
                    ->where('secondary_norad_id', $debris['norad_id'])
                    ->where('tca', '>=', (clone $tcaRounded)->modify('-1 hour'))
                    ->where('tca', '<=', (clone $tcaRounded)->modify('+1 hour'))
                    ->exists();

                if ($exists) {
                    continue;
                }

                $this->line('');
                $this->warn(sprintf(
                    '  CONJUNCTION: %s ↔ %s | TCA %s | %.3f km | risk %d',
                    $sat->name ?? $sat->norad_id,
                    $debris['name'],
                    $tcaRounded->format('Y-m-d H:i'),
                    $result['miss_km'],
                    $result['risk_score'],
                ));

                if ($isDryRun) {
                    $newAlerts++;
                    continue;
                }

                $alert = ConjunctionAlert::create([
                    'primary_norad_id'   => $sat->norad_id,
                    'primary_name'       => $sat->name ?? $sat->norad_id,
                    'secondary_norad_id' => $debris['norad_id'],
                    'secondary_name'     => $debris['name'],
                    'tca'                => $tcaRounded,
                    'miss_distance_km'   => $result['miss_km'],
                    'risk_score'         => $result['risk_score'],
                ]);

                $this->notifyWatchers($sat->norad_id, $alert);
                $newAlerts++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->line('');
        $this->info("Done. {$newAlerts} new conjunction alert(s) created.");

        return self::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Fetch a single satellite's TLE from CelesTrak by NORAD ID.
     *
     * @return array{name: string, line1: string, line2: string}|null
     */
    private function fetchTle(string $noradId): ?array
    {
        try {
            $response = Http::timeout(10)->get(self::CELESTRAK_TLE_URL, [
                'CATNR'  => $noradId,
                'FORMAT' => 'TLE',
            ]);

            if (! $response->ok()) {
                return null;
            }

            return $this->parseTleBlock($response->body());
        } catch (\Throwable $e) {
            Log::warning("TLE fetch failed for {$noradId}: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Fetch TLEs for all debris groups and return a flat catalog array.
     *
     * @return list<array{norad_id: string, name: string, line1: string, line2: string}>
     */
    private function fetchDebrisCatalog(): array
    {
        $catalog = [];

        foreach (self::DEBRIS_GROUPS as $group) {
            try {
                $response = Http::timeout(30)->get(self::CELESTRAK_GROUP_URL, [
                    'GROUP'  => $group,
                    'FORMAT' => 'TLE',
                ]);

                if (! $response->ok()) {
                    $this->warn("  Could not fetch group: {$group}");
                    continue;
                }

                $entries = $this->parseTleList($response->body());
                $this->line("  {$group}: " . count($entries) . ' objects');
                $catalog = array_merge($catalog, $entries);
            } catch (\Throwable $e) {
                Log::warning("Debris fetch failed for {$group}: {$e->getMessage()}");
            }
        }

        // Remove duplicates by NORAD ID
        $seen   = [];
        $unique = [];
        foreach ($catalog as $entry) {
            if (! isset($seen[$entry['norad_id']])) {
                $seen[$entry['norad_id']] = true;
                $unique[]                 = $entry;
            }
        }

        return $unique;
    }

    /**
     * Parse a single TLE block (3 lines: name, line1, line2).
     *
     * @return array{name: string, line1: string, line2: string}|null
     */
    private function parseTleBlock(string $raw): ?array
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", trim($raw))),
            fn ($l) => $l !== ''
        ));

        if (count($lines) < 3) {
            return null;
        }

        return [
            'name'  => $lines[0],
            'line1' => $lines[1],
            'line2' => $lines[2],
        ];
    }

    /**
     * Parse a TLE list response into multiple entries.
     *
     * @return list<array{norad_id: string, name: string, line1: string, line2: string}>
     */
    private function parseTleList(string $raw): array
    {
        $lines   = array_values(array_filter(
            array_map('trim', explode("\n", trim($raw))),
            fn ($l) => $l !== ''
        ));
        $entries = [];

        for ($i = 0; $i + 2 < count($lines); $i += 3) {
            $name  = $lines[$i];
            $line1 = $lines[$i + 1];
            $line2 = $lines[$i + 2];

            if (! str_starts_with($line1, '1 ') || ! str_starts_with($line2, '2 ')) {
                continue;
            }

            $noradId = trim(substr($line1, 2, 5));

            $entries[] = [
                'norad_id' => $noradId,
                'name'     => $name,
                'line1'    => $line1,
                'line2'    => $line2,
            ];
        }

        return $entries;
    }

    /** Send a notification to every user watching the primary satellite. */
    private function notifyWatchers(string $noradId, ConjunctionAlert $alert): void
    {
        $users = User::whereHas(
            'watchedSatellites',
            fn ($q) => $q->where('norad_id', $noradId)
        )->get();

        foreach ($users as $user) {
            try {
                $user->notify(new ConjunctionAlertNotification($alert));
            } catch (\Throwable $e) {
                Log::error("Notification failed for user {$user->id}: {$e->getMessage()}");
            }
        }

        if ($users->isNotEmpty() && ! $this->option('dry-run')) {
            $alert->update(['notified_at' => now()]);
        }
    }
}
