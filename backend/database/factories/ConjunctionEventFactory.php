<?php

namespace Database\Factories;

use App\Models\ConjunctionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConjunctionEvent>
 *
 * Produces realistic CDM events for tests and local demo.
 * These mimic the shape of real Space-Track CDM_PUBLIC records.
 */
class ConjunctionEventFactory extends Factory
{
    protected $model = ConjunctionEvent::class;

    private const DEBRIS_PAIRS = [
        ['sat1' => '25544', 'name1' => 'ISS (ZARYA)',       'sat2' => '29228', 'name2' => 'FENGYUN 1C DEB'],
        ['sat1' => '25544', 'name1' => 'ISS (ZARYA)',       'sat2' => '33764', 'name2' => 'COSMOS 2251 DEB'],
        ['sat1' => '20580', 'name1' => 'HST',               'sat2' => '33442', 'name2' => 'IRIDIUM 33 DEB'],
        ['sat1' => '43013', 'name1' => 'GOES-16',           'sat2' => '28884', 'name2' => 'FENGYUN 1C DEB'],
        ['sat1' => '25544', 'name1' => 'ISS (ZARYA)',       'sat2' => '39115', 'name2' => 'COSMOS 1408 DEB'],
    ];

    public function definition(): array
    {
        $pair    = fake()->randomElement(self::DEBRIS_PAIRS);
        $minRng  = round(fake()->randomFloat(3, 0.1, 9.9), 3);
        $pc      = round(fake()->randomFloat(9, 0.0000001, 0.001), 9);
        $tca     = fake()->dateTimeBetween('+2 hours', '+6 days');

        return [
            'cdm_id'               => (string) fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'created_at_cdm'       => fake()->dateTimeBetween('-2 days', 'now'),
            'tca'                  => $tca,
            'min_range_km'         => $minRng,
            'probability'          => $pc,
            'emergency_reportable' => false,
            'sat1_norad_id'        => $pair['sat1'],
            'sat1_name'            => $pair['name1'],
            'sat2_norad_id'        => $pair['sat2'],
            'sat2_name'            => $pair['name2'],
            'source'               => 'space_track_cdm',
            'fetched_at'           => now(),
        ];
    }

    // ── Risk states ───────────────────────────────────────────────────────

    /** Miss distance < 1 km, PC high → HIGH risk. */
    public function high(): static
    {
        return $this->state([
            'min_range_km' => round(fake()->randomFloat(3, 0.05, 0.9), 3),
            'probability'  => round(fake()->randomFloat(6, 0.001, 0.009), 6),
        ]);
    }

    /** Miss distance 1–4 km, PC mid → MEDIUM risk. */
    public function medium(): static
    {
        return $this->state([
            'min_range_km' => round(fake()->randomFloat(3, 1.0, 4.0), 3),
            'probability'  => round(fake()->randomFloat(8, 0.00001, 0.001), 8),
        ]);
    }

    /** Miss distance > 6 km, PC very low → LOW risk (score < 40). */
    public function low(): static
    {
        return $this->state([
            'min_range_km' => round(fake()->randomFloat(3, 7.0, 9.9), 3),
            'probability'  => round(fake()->randomFloat(10, 0.0000001, 0.0000009), 10),
        ]);
    }

    /** TCA is well in the past — outside the active() 24h lookback window. */
    public function past(): static
    {
        return $this->state([
            'tca' => fake()->dateTimeBetween('-7 days', '-2 days'),
        ]);
    }

    /** Override satellite 1 (primary). */
    public function forPrimary(string $noradId, string $name = ''): static
    {
        return $this->state([
            'sat1_norad_id' => $noradId,
            'sat1_name'     => $name ?: $noradId,
        ]);
    }

    /** Override satellite 2 (secondary). */
    public function forSecondary(string $noradId, string $name = ''): static
    {
        return $this->state([
            'sat2_norad_id' => $noradId,
            'sat2_name'     => $name ?: $noradId,
        ]);
    }
}
