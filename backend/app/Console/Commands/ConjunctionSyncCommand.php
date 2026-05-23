<?php

namespace App\Console\Commands;

use App\Models\ConjunctionAlert;
use App\Models\ConjunctionEvent;
use App\Models\User;
use App\Models\WatchedSatellite;
use App\Notifications\ConjunctionAlertNotification;
use App\Services\SpaceTrackClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Fetch real conjunction data from Space-Track CDM_PUBLIC and store it locally.
 *
 * Flow:
 *   1. Authenticate with Space-Track (SPACE_TRACK_USER / SPACE_TRACK_PASS).
 *   2. Fetch CDM_PUBLIC — conjunction messages for the next 7 days.
 *   3. Upsert into conjunction_events by cdm_id (idempotent).
 *   4. For each event, check if either object is watched by any user.
 *   5. If watched: create a conjunction_alert (dedup by primary+secondary+TCA ±1h).
 *   6. Notify any watchers for new alerts.
 *
 * Running without credentials: the command exits cleanly with a warning.
 * This makes it safe to schedule unconditionally — it's a no-op without creds.
 *
 * Schedule: console.php runs this every 6 hours alongside conjunctions:check.
 *
 * Local run:
 *   php artisan conjunctions:sync
 *   php artisan conjunctions:sync --dry-run
 */
class ConjunctionSyncCommand extends Command
{
    protected $signature = 'conjunctions:sync
                                {--dry-run : Fetch and parse CDM data but skip DB writes and notifications}';

    protected $description = 'Fetch real conjunction data from Space-Track CDM and store locally';

    public function handle(SpaceTrackClient $client): int
    {
        $isDryRun = $this->option('dry-run');
        $user = config('services.space_track.user');
        $pass = config('services.space_track.pass');

        if (! $user || ! $pass) {
            $this->warn('SPACE_TRACK_USER / SPACE_TRACK_PASS not configured.');
            $this->warn('Skipping CDM sync. Set these in .env to enable real conjunction data.');
            $this->warn('Free account: https://www.space-track.org/auth/#/login');

            return self::SUCCESS;
        }

        $this->info('Logging in to Space-Track.org…');

        if (! $client->login($user, $pass)) {
            $this->error('Space-Track login failed. Check SPACE_TRACK_USER / SPACE_TRACK_PASS in .env.');

            return self::FAILURE;
        }

        $this->info('Fetching CDM_PUBLIC data…');
        $cdmData = $client->fetchCdm();

        if (empty($cdmData)) {
            $this->warn('No CDM events returned. Space-Track may be unavailable or returning empty data.');

            return self::SUCCESS;
        }

        $this->info('Received '.count($cdmData).' CDM records.');

        // Load watched NORAD IDs once — group by NORAD ID for O(1) lookup.
        $watchedByNorad = WatchedSatellite::with('user')->get()->groupBy('norad_id');

        $upserted = 0;
        $alertsCreated = 0;
        $skipped = 0;

        foreach ($cdmData as $raw) {
            try {
                $event = $this->processCdmRecord($raw, $isDryRun);
            } catch (\Throwable $e) {
                Log::warning('[ConjunctionSync] Could not process CDM record: '.$e->getMessage(), ['raw' => $raw]);
                $skipped++;

                continue;
            }

            if ($event === null) {
                $skipped++;

                continue;
            }

            $upserted++;

            if (! $isDryRun) {
                $alertsCreated += $this->generateAlerts($event, $watchedByNorad);
            }
        }

        $this->line('');
        $this->info("Done. {$upserted} events upserted, {$alertsCreated} alert(s) created, {$skipped} skipped.");

        return self::SUCCESS;
    }

    // ── CDM record processing ─────────────────────────────────────────────

    /**
     * Parse and upsert one CDM record.
     * Returns the model on success, null if the record is malformed.
     */
    private function processCdmRecord(array $raw, bool $isDryRun): ?ConjunctionEvent
    {
        $cdmId = (string) ($raw['CDM_ID'] ?? '');
        if ($cdmId === '') {
            return null;
        }

        $tca = $this->parseUtc($raw['TCA'] ?? '');
        $created = $this->parseUtc($raw['CREATED'] ?? '');

        if ($tca === null) {
            Log::debug("[ConjunctionSync] Skipping CDM {$cdmId} — unparseable TCA.");

            return null;
        }

        $sat1 = trim((string) ($raw['SAT_1_ID'] ?? ''));
        $sat2 = trim((string) ($raw['SAT_2_ID'] ?? ''));

        if ($sat1 === '' || $sat2 === '') {
            return null;
        }

        // Normalise to 5-digit zero-padded to match the satellites table.
        $sat1 = str_pad($sat1, 5, '0', STR_PAD_LEFT);
        $sat2 = str_pad($sat2, 5, '0', STR_PAD_LEFT);

        $minRng = (float) ($raw['MIN_RNG'] ?? 0);
        $pc = isset($raw['PC']) && $raw['PC'] !== '' ? (float) $raw['PC'] : null;

        if ($isDryRun) {
            $this->line(sprintf(
                '  [dry-run] CDM %s | %s ↔ %s | TCA %s | %.3f km | PC %s',
                $cdmId,
                $raw['SAT_1_NAME'] ?? $sat1,
                $raw['SAT_2_NAME'] ?? $sat2,
                $tca->format('Y-m-d H:i'),
                $minRng,
                $pc !== null ? number_format($pc, 8) : 'N/A',
            ));

            // Return a transient model (not persisted) so callers can inspect the value.
            return new ConjunctionEvent([
                'cdm_id' => $cdmId,
                'created_at_cdm' => $created,
                'tca' => $tca,
                'min_range_km' => $minRng,
                'probability' => $pc,
                'emergency_reportable' => ($raw['EMERGENCY_REPORTABLE'] ?? 'N') === 'Y',
                'sat1_norad_id' => $sat1,
                'sat1_name' => substr((string) ($raw['SAT_1_NAME'] ?? $sat1), 0, 120),
                'sat2_norad_id' => $sat2,
                'sat2_name' => substr((string) ($raw['SAT_2_NAME'] ?? $sat2), 0, 120),
                'source' => 'space_track_cdm',
                'fetched_at' => now(),
            ]);
        }

        return ConjunctionEvent::updateOrCreate(
            ['cdm_id' => $cdmId],
            [
                'created_at_cdm' => $created,
                'tca' => $tca,
                'min_range_km' => $minRng,
                'probability' => $pc,
                'emergency_reportable' => ($raw['EMERGENCY_REPORTABLE'] ?? 'N') === 'Y',
                'sat1_norad_id' => $sat1,
                'sat1_name' => substr((string) ($raw['SAT_1_NAME'] ?? $sat1), 0, 120),
                'sat2_norad_id' => $sat2,
                'sat2_name' => substr((string) ($raw['SAT_2_NAME'] ?? $sat2), 0, 120),
                'source' => 'space_track_cdm',
                'fetched_at' => now(),
            ],
        );
    }

    // ── Alert generation from CDM events ─────────────────────────────────

    /**
     * For a given CDM event, create conjunction_alerts for any watched satellite
     * that appears as sat1 or sat2. Returns the number of new alerts created.
     *
     * @param  Collection<string, Collection>  $watchedByNorad
     */
    private function generateAlerts(ConjunctionEvent $event, $watchedByNorad): int
    {
        $created = 0;

        // Check both directions: sat1 as primary, sat2 as secondary; and vice-versa.
        $pairs = [
            ['primary' => $event->sat1_norad_id, 'primary_name' => $event->sat1_name,
                'secondary' => $event->sat2_norad_id, 'secondary_name' => $event->sat2_name],
            ['primary' => $event->sat2_norad_id, 'primary_name' => $event->sat2_name,
                'secondary' => $event->sat1_norad_id, 'secondary_name' => $event->sat1_name],
        ];

        foreach ($pairs as $pair) {
            if (! $watchedByNorad->has($pair['primary'])) {
                continue;
            }

            // Round TCA to the nearest minute for deduplication.
            $tca = Carbon::instance($event->tca)->setSecond(0)->setMicrosecond(0);

            // Skip if we already have an alert for this pair within ±1 hour.
            $exists = ConjunctionAlert::where('primary_norad_id', $pair['primary'])
                ->where('secondary_norad_id', $pair['secondary'])
                ->where('conjunction_event_id', $event->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $riskScore = $event->riskScore();

            $alert = ConjunctionAlert::create([
                'primary_norad_id' => $pair['primary'],
                'primary_name' => $pair['primary_name'],
                'secondary_norad_id' => $pair['secondary'],
                'secondary_name' => $pair['secondary_name'],
                'tca' => $tca,
                'miss_distance_km' => $event->min_range_km,
                'probability' => $event->probability,
                'risk_score' => $riskScore,
                'source' => 'space_track_cdm',
                'conjunction_event_id' => $event->id,
            ]);

            $this->notifyWatchers($pair['primary'], $alert, $watchedByNorad);
            $created++;
        }

        return $created;
    }

    /** Send notifications to users watching the primary satellite. */
    private function notifyWatchers(string $noradId, ConjunctionAlert $alert, $watchedByNorad): void
    {
        $sats = $watchedByNorad->get($noradId, collect());

        $userIds = $sats->pluck('user_id')->unique();

        $users = User::whereIn('id', $userIds)->get();

        foreach ($users as $user) {
            try {
                $user->notify(new ConjunctionAlertNotification($alert));
            } catch (\Throwable $e) {
                Log::error("[ConjunctionSync] Notification failed for user {$user->id}: {$e->getMessage()}");
            }
        }

        if ($users->isNotEmpty()) {
            $alert->update(['notified_at' => now()]);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Parse a UTC datetime string from Space-Track into a Carbon instance. */
    private function parseUtc(string $raw): ?Carbon
    {
        if ($raw === '') {
            return null;
        }
        try {
            return Carbon::parse($raw, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}
