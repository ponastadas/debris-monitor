<?php

namespace Database\Seeders;

use App\Models\ConjunctionAlert;
use App\Models\ConjunctionEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WatchedSatellite;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * ConjunctionEventSeeder — OPT-IN ONLY demo CDM events for local dev / screenshots.
 *
 * NOT included in DatabaseSeeder. Run explicitly when you need the Tracker CDM
 * path populated without real Space-Track credentials.
 *
 *   php artisan db:seed --class=ConjunctionEventSeeder
 *   make artisan cmd="db:seed --class=ConjunctionEventSeeder"  (alias: make seed-conjunctions)
 *
 * All rows are tagged source='demo' (cdm_id prefix DEMO-) and can be removed with:
 *   php artisan alerts:purge-demo
 */
class ConjunctionEventSeeder extends Seeder
{
    /** Well-known conjunction pairs. CDM_IDs are fictional but stable. */
    private const DEMO_EVENTS = [
        [
            'cdm_id' => 'DEMO-0001',
            'created_at_cdm' => '-18 hours',
            'tca_offset' => '+6 hours',
            'min_range_km' => 0.312,
            'probability' => 0.00823456,
            'emergency_reportable' => true,
            'sat1_norad_id' => '25544',
            'sat1_name' => 'ISS (ZARYA)',
            'sat2_norad_id' => '22675',
            'sat2_name' => 'COSMOS 2251 DEB',
        ],
        [
            'cdm_id' => 'DEMO-0002',
            'created_at_cdm' => '-12 hours',
            'tca_offset' => '+34 hours',
            'min_range_km' => 1.847,
            'probability' => 0.00041200,
            'emergency_reportable' => false,
            'sat1_norad_id' => '25544',
            'sat1_name' => 'ISS (ZARYA)',
            'sat2_norad_id' => '33442',
            'sat2_name' => 'IRIDIUM 33 DEB',
        ],
        [
            'cdm_id' => 'DEMO-0003',
            'created_at_cdm' => '-6 hours',
            'tca_offset' => '+72 hours',
            'min_range_km' => 3.912,
            'probability' => 0.00000870,
            'emergency_reportable' => false,
            'sat1_norad_id' => '25544',
            'sat1_name' => 'ISS (ZARYA)',
            'sat2_norad_id' => '28884',
            'sat2_name' => 'FENGYUN 1C DEB',
        ],
        [
            'cdm_id' => 'DEMO-0004',
            'created_at_cdm' => '-10 hours',
            'tca_offset' => '+18 hours',
            'min_range_km' => 0.781,
            'probability' => 0.00312000,
            'emergency_reportable' => true,
            'sat1_norad_id' => '20580',
            'sat1_name' => 'HST',
            'sat2_norad_id' => '39115',
            'sat2_name' => 'COSMOS 1408 DEB',
        ],
        [
            'cdm_id' => 'DEMO-0005',
            'created_at_cdm' => '-8 hours',
            'tca_offset' => '+96 hours',
            'min_range_km' => 2.341,
            'probability' => 0.00018900,
            'emergency_reportable' => false,
            'sat1_norad_id' => '20580',
            'sat1_name' => 'HST',
            'sat2_norad_id' => '41789',
            'sat2_name' => 'SL-8 R/B',
        ],
        [
            'cdm_id' => 'DEMO-0006',
            'created_at_cdm' => '-4 hours',
            'tca_offset' => '+48 hours',
            'min_range_km' => 4.520,
            'probability' => 0.00000210,
            'emergency_reportable' => false,
            'sat1_norad_id' => '43013',
            'sat1_name' => 'GOES-16',
            'sat2_norad_id' => '29230',
            'sat2_name' => 'FENGYUN 1C DEB',
        ],
    ];

    public function run(): void
    {
        // ── 1. Upsert conjunction events ──────────────────────────────────

        foreach (self::DEMO_EVENTS as $def) {
            ConjunctionEvent::updateOrCreate(
                ['cdm_id' => $def['cdm_id']],
                [
                    'created_at_cdm' => now()->modify($def['created_at_cdm']),
                    'tca' => now()->modify($def['tca_offset'])->setSecond(0)->setMicrosecond(0),
                    'min_range_km' => $def['min_range_km'],
                    'probability' => $def['probability'],
                    'emergency_reportable' => $def['emergency_reportable'],
                    'sat1_norad_id' => $def['sat1_norad_id'],
                    'sat1_name' => $def['sat1_name'],
                    'sat2_norad_id' => $def['sat2_norad_id'],
                    'sat2_name' => $def['sat2_name'],
                    'source' => 'demo',
                    'fetched_at' => now(),
                ],
            );
        }

        $this->command->info('  [CDM]   '.count(self::DEMO_EVENTS).' demo conjunction events upserted.');

        // ── 2. Create demo user with CDM-backed alerts ────────────────────
        // Re-use the same demo@satview.eu user from AlertDemoSeeder.

        $demo = User::firstOrCreate(
            ['email' => 'demo@satview.eu'],
            ['name' => 'Demo User', 'password' => Hash::make('password'), 'status' => 'active'],
        );

        Subscription::firstOrCreate(
            ['user_id' => $demo->id, 'name' => 'default'],
            [
                'plan' => 'starter',
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addYear(),
            ],
        );

        WatchedSatellite::firstOrCreate(
            ['user_id' => $demo->id, 'norad_id' => '25544'],
            ['name' => 'ISS (ZARYA)'],
        );

        WatchedSatellite::firstOrCreate(
            ['user_id' => $demo->id, 'norad_id' => '20580'],
            ['name' => 'HST'],
        );

        // ── 3. Generate conjunction_alerts from CDM events ────────────────
        // Only create alerts for NORAD IDs the demo user is watching.

        $watchedIds = ['25544', '20580'];

        foreach (self::DEMO_EVENTS as $def) {
            $event = ConjunctionEvent::where('cdm_id', $def['cdm_id'])->first();
            if (! $event) {
                continue;
            }

            foreach ($watchedIds as $noradId) {
                if ($event->sat1_norad_id !== $noradId && $event->sat2_norad_id !== $noradId) {
                    continue;
                }

                $isPrimary = $event->sat1_norad_id === $noradId;
                $secondaryNorad = $isPrimary ? $event->sat2_norad_id : $event->sat1_norad_id;

                // Use updateOrCreate on the unique constraint so this seeder
                // is idempotent even when AlertDemoSeeder has already created an
                // alert for the same (primary, secondary, tca) triple.
                ConjunctionAlert::updateOrCreate(
                    [
                        'primary_norad_id' => $noradId,
                        'secondary_norad_id' => $secondaryNorad,
                        'tca' => $event->tca,
                    ],
                    [
                        'primary_name' => $isPrimary ? $event->sat1_name : $event->sat2_name,
                        'secondary_name' => $isPrimary ? $event->sat2_name : $event->sat1_name,
                        'miss_distance_km' => $event->min_range_km,
                        'probability' => $event->probability,
                        'risk_score' => $event->riskScore(),
                        'source' => 'demo',
                        'conjunction_event_id' => $event->id,
                        'notified_at' => now(),
                    ],
                );
            }
        }

        $this->command->info('  [CDM]   Conjunction alerts generated for demo@satview.eu (ISS + Hubble).');
    }
}
