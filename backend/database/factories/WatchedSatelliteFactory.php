<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WatchedSatellite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchedSatellite>
 */
class WatchedSatelliteFactory extends Factory
{
    protected $model = WatchedSatellite::class;

    private const KNOWN = [
        ['norad_id' => '25544', 'name' => 'ISS (ZARYA)'],
        ['norad_id' => '20580', 'name' => 'HST'],
        ['norad_id' => '43013', 'name' => 'GOES-16'],
        ['norad_id' => '37849', 'name' => 'TIANGONG 1'],
    ];

    public function definition(): array
    {
        $sat = fake()->randomElement(self::KNOWN);

        return [
            'user_id' => User::factory(),
            'norad_id' => $sat['norad_id'],
            'name' => $sat['name'],
            'tle_line1' => null,
            'tle_line2' => null,
            'tle_fetched_at' => null,
        ];
    }

    /** Pin to ISS. */
    public function iss(): static
    {
        return $this->state(['norad_id' => '25544', 'name' => 'ISS (ZARYA)']);
    }

    /** Pin to Hubble. */
    public function hubble(): static
    {
        return $this->state(['norad_id' => '20580', 'name' => 'HST']);
    }

    /** Override NORAD ID and name freely. */
    public function forNorad(string $noradId, string $name = ''): static
    {
        return $this->state(['norad_id' => $noradId, 'name' => $name ?: $noradId]);
    }

    /** Mark TLE as freshly fetched (within the 6-hour window). */
    public function withFreshTle(): static
    {
        return $this->state(['tle_fetched_at' => now()]);
    }
}
