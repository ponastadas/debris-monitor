<?php

namespace Database\Seeders;

use App\Models\ConjunctionAlert;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WatchedSatellite;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * AlertDemoSeeder — local demo data for the Alerts feature.
 *
 * Creates three demo users that cover all Alerts UI states:
 *
 *   demo@debris.monitor  / password  (starter)  → watched sats + alerts
 *   free@debris.monitor  / password  (free)      → watched sat, no alerts visible
 *   empty@debris.monitor / password  (starter)   → no watched satellites
 *
 * Safe to re-run: existing demo users are reused; alerts are recreated only
 * when no upcoming alerts exist for their primary NORAD IDs (i.e. after expiry).
 *
 * Run locally:
 *   php artisan db:seed --class=AlertDemoSeeder
 * Or via Docker:
 *   make artisan cmd="db:seed --class=AlertDemoSeeder"
 */
class AlertDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Demo user (starter) — watched sats + alerts ────────────────

        $demo = User::firstOrCreate(
            ['email' => 'demo@debris.monitor'],
            ['name' => 'Demo User', 'password' => Hash::make('password'), 'status' => 'active'],
        );

        Subscription::firstOrCreate(
            ['user_id' => $demo->id, 'name' => 'default'],
            [
                'plan'                 => 'starter',
                'status'               => 'active',
                'current_period_start' => now(),
                'current_period_end'   => now()->addYear(),
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

        // Recreate ISS alerts only when all existing ones have expired
        if (! ConjunctionAlert::upcoming()->where('primary_norad_id', '25544')->exists()) {
            ConjunctionAlert::where('primary_norad_id', '25544')
                ->whereIn('secondary_norad_id', ['22675', '33442', '28884'])
                ->delete();

            ConjunctionAlert::insert([
                // HIGH — 6 hours away
                [
                    'primary_norad_id'   => '25544',
                    'primary_name'       => 'ISS (ZARYA)',
                    'secondary_norad_id' => '22675',
                    'secondary_name'     => 'COSMOS 2251 DEB',
                    'tca'                => now()->addHours(6)->setSecond(0)->setMicrosecond(0),
                    'miss_distance_km'   => 0.312,
                    'probability'        => 0.00823456,
                    'risk_score'         => 88,
                    'notified_at'        => now(),
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ],
                // MEDIUM — 1.4 days away
                [
                    'primary_norad_id'   => '25544',
                    'primary_name'       => 'ISS (ZARYA)',
                    'secondary_norad_id' => '33442',
                    'secondary_name'     => 'IRIDIUM 33 DEB',
                    'tca'                => now()->addHours(34)->setSecond(0)->setMicrosecond(0),
                    'miss_distance_km'   => 1.847,
                    'probability'        => 0.00041200,
                    'risk_score'         => 54,
                    'notified_at'        => now(),
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ],
                // LOW — 3 days away
                [
                    'primary_norad_id'   => '25544',
                    'primary_name'       => 'ISS (ZARYA)',
                    'secondary_norad_id' => '28884',
                    'secondary_name'     => 'FENGYUN 1C DEB',
                    'tca'                => now()->addHours(72)->setSecond(0)->setMicrosecond(0),
                    'miss_distance_km'   => 3.912,
                    'probability'        => 0.00000870,
                    'risk_score'         => 22,
                    'notified_at'        => null,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ],
            ]);
        }

        // Recreate Hubble alerts only when expired
        if (! ConjunctionAlert::upcoming()->where('primary_norad_id', '20580')->exists()) {
            ConjunctionAlert::where('primary_norad_id', '20580')
                ->whereIn('secondary_norad_id', ['39115', '41789'])
                ->delete();

            ConjunctionAlert::insert([
                // HIGH — 18 hours away
                [
                    'primary_norad_id'   => '20580',
                    'primary_name'       => 'HST',
                    'secondary_norad_id' => '39115',
                    'secondary_name'     => 'COSMOS 1408 DEB',
                    'tca'                => now()->addHours(18)->setSecond(0)->setMicrosecond(0),
                    'miss_distance_km'   => 0.781,
                    'probability'        => 0.00312000,
                    'risk_score'         => 76,
                    'notified_at'        => now(),
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ],
                // MEDIUM — 4 days away
                [
                    'primary_norad_id'   => '20580',
                    'primary_name'       => 'HST',
                    'secondary_norad_id' => '41789',
                    'secondary_name'     => 'SL-8 R/B',
                    'tca'                => now()->addHours(96)->setSecond(0)->setMicrosecond(0),
                    'miss_distance_km'   => 2.341,
                    'probability'        => 0.00018900,
                    'risk_score'         => 45,
                    'notified_at'        => null,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ],
            ]);
        }

        $this->command->info('  [demo]  demo@debris.monitor (starter) — ISS + Hubble watched, 5 alerts');

        // ── 2. Free user — sees gate, can't view alerts ────────────────────

        $free = User::firstOrCreate(
            ['email' => 'free@debris.monitor'],
            ['name' => 'Free User', 'password' => Hash::make('password'), 'status' => 'active'],
        );

        // Free user has no subscription row — currentPlan() returns 'free'

        WatchedSatellite::firstOrCreate(
            ['user_id' => $free->id, 'norad_id' => '25544'],
            ['name' => 'ISS (ZARYA)'],
        );

        $this->command->info('  [demo]  free@debris.monitor (free) — ISS watched, upgrade gate shown');

        // ── 3. Starter user with no watched satellites ────────────────────

        $empty = User::firstOrCreate(
            ['email' => 'empty@debris.monitor'],
            ['name' => 'Empty User', 'password' => Hash::make('password'), 'status' => 'active'],
        );

        Subscription::firstOrCreate(
            ['user_id' => $empty->id, 'name' => 'default'],
            [
                'plan'                 => 'starter',
                'status'               => 'active',
                'current_period_start' => now(),
                'current_period_end'   => now()->addYear(),
            ],
        );

        $this->command->info('  [demo]  empty@debris.monitor (starter) — no watched sats, empty alerts state');
    }
}
