<?php

namespace App\Console\Commands;

use App\Models\ConjunctionAlert;
use App\Models\ConjunctionEvent;
use Illuminate\Console\Command;

/**
 * Remove demo/fake conjunction data without touching real CDM or SGP4 rows.
 *
 * Targets:
 *   conjunction_alerts  where source = 'demo'
 *   conjunction_alerts  where source IS NULL AND conjunction_event_id IS NULL
 *                         (rows created by AlertDemoSeeder before the source field was added)
 *   conjunction_alerts  where conjunction_event_id points to a DEMO-* event
 *                         (rows from ConjunctionEventSeeder before the source field was added)
 *   conjunction_events  where cdm_id LIKE 'DEMO-%'
 *
 * Preserves all rows with source = 'space_track_cdm' or source = 'sgp4'.
 */
class PurgeDemoAlertsCommand extends Command
{
    protected $signature   = 'alerts:purge-demo
                                {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Delete demo/fake conjunction alerts and events; preserves real CDM and SGP4 rows';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        // Demo event IDs — identified by the DEMO- cdm_id prefix used by ConjunctionEventSeeder.
        $demoEventIds = ConjunctionEvent::where('cdm_id', 'like', 'DEMO-%')->pluck('id');

        $alertCount = ConjunctionAlert::where(function ($q) use ($demoEventIds) {
            $q->where('source', 'demo')
                ->orWhere(function ($inner) {
                    // Null-source + null-event = standalone fake row from old AlertDemoSeeder
                    $inner->whereNull('source')->whereNull('conjunction_event_id');
                });

            if ($demoEventIds->isNotEmpty()) {
                // Alerts linked to demo events regardless of source label
                $q->orWhereIn('conjunction_event_id', $demoEventIds);
            }
        })->count();

        $eventCount = $demoEventIds->count();

        $this->line("Found {$alertCount} demo alert(s) and {$eventCount} demo event(s).");

        if ($alertCount === 0 && $eventCount === 0) {
            $this->info('Nothing to purge.');
            return self::SUCCESS;
        }

        if ($isDryRun) {
            $this->warn('[dry-run] No rows deleted. Remove --dry-run to execute.');
            return self::SUCCESS;
        }

        // Delete alerts first to avoid FK constraint issues.
        $deletedAlerts = ConjunctionAlert::where(function ($q) use ($demoEventIds) {
            $q->where('source', 'demo')
                ->orWhere(function ($inner) {
                    $inner->whereNull('source')->whereNull('conjunction_event_id');
                });

            if ($demoEventIds->isNotEmpty()) {
                $q->orWhereIn('conjunction_event_id', $demoEventIds);
            }
        })->delete();

        $deletedEvents = ConjunctionEvent::where('cdm_id', 'like', 'DEMO-%')->delete();

        $this->info("Purged {$deletedAlerts} alert(s) and {$deletedEvents} event(s).");
        $this->info('Real CDM (space_track_cdm) and SGP4 (sgp4) rows preserved.');

        return self::SUCCESS;
    }
}
