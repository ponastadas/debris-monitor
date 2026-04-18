<?php

namespace Database\Factories;

use App\Models\ConjunctionAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConjunctionAlert>
 *
 * Risk score formula mirrors TlePropagator::riskScore():
 *   score = 100 * (1 - miss_km / 5.0)
 *
 * States: high(), medium(), low(), notified(), past()
 */
class ConjunctionAlertFactory extends Factory
{
    protected $model = ConjunctionAlert::class;

    private const DEBRIS_NAMES = [
        'COSMOS 2251 DEB',
        'IRIDIUM 33 DEB',
        'FENGYUN 1C DEB',
        'COSMOS 1408 DEB',
        'STARLINK DEB',
        'SL-8 R/B',
        'CZ-4B DEB',
    ];

    public function definition(): array
    {
        $missKm    = round(fake()->randomFloat(3, 0.1, 4.9), 3);
        $riskScore = max(0, min(100, (int) round(100 * (1 - $missKm / 5.0))));

        return [
            'primary_norad_id'   => '25544',
            'primary_name'       => 'ISS (ZARYA)',
            'secondary_norad_id' => (string) fake()->numberBetween(10000, 89999),
            'secondary_name'     => fake()->randomElement(self::DEBRIS_NAMES),
            'tca'                => fake()->dateTimeBetween('+1 hours', '+5 days'),
            'miss_distance_km'   => $missKm,
            'probability'        => round(fake()->randomFloat(8, 0.0000001, 0.009), 8),
            'risk_score'         => $riskScore,
            'notified_at'        => null,
        ];
    }

    // ── Risk states ───────────────────────────────────────────────────────

    /** risk_score ≥ 70, miss < 1 km */
    public function high(): static
    {
        return $this->state(function () {
            $miss = round(fake()->randomFloat(3, 0.05, 0.8), 3);
            return [
                'miss_distance_km' => $miss,
                'probability'      => round(fake()->randomFloat(8, 0.001, 0.009), 8),
                'risk_score'       => fake()->numberBetween(75, 100),
            ];
        });
    }

    /** risk_score 40–69, miss 1–3 km */
    public function medium(): static
    {
        return $this->state(function () {
            $miss = round(fake()->randomFloat(3, 1.0, 3.0), 3);
            return [
                'miss_distance_km' => $miss,
                'probability'      => round(fake()->randomFloat(8, 0.00001, 0.001), 8),
                'risk_score'       => fake()->numberBetween(40, 69),
            ];
        });
    }

    /** risk_score 0–39, miss 3–5 km */
    public function low(): static
    {
        return $this->state(function () {
            $miss = round(fake()->randomFloat(3, 3.0, 4.9), 3);
            return [
                'miss_distance_km' => $miss,
                'probability'      => round(fake()->randomFloat(8, 0.0000001, 0.00001), 8),
                'risk_score'       => fake()->numberBetween(0, 39),
            ];
        });
    }

    // ── TCA states ────────────────────────────────────────────────────────

    /** Alert whose TCA is in the past (won't appear in upcoming() scope). */
    public function past(): static
    {
        return $this->state(['tca' => fake()->dateTimeBetween('-5 days', '-1 hours')]);
    }

    /** Alert whose TCA is more than 5 days away (outside upcoming() window). */
    public function distant(): static
    {
        return $this->state(['tca' => fake()->dateTimeBetween('+6 days', '+30 days')]);
    }

    // ── Misc states ───────────────────────────────────────────────────────

    /** Mark as already notified. */
    public function notified(): static
    {
        return $this->state(['notified_at' => now()]);
    }

    /** Override primary satellite. */
    public function forPrimary(string $noradId, string $name = ''): static
    {
        return $this->state([
            'primary_norad_id' => $noradId,
            'primary_name'     => $name ?: $noradId,
        ]);
    }
}
