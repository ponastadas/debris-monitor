<?php

namespace Database\Factories;

use App\Models\Satellite;
use App\Models\TleRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TleRecord>
 */
class TleRecordFactory extends Factory
{
    protected $model = TleRecord::class;

    // Valid-format ISS TLE (epoch adjusted for tests — not a live TLE)
    private const ISS_LINE1 = '1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990';

    private const ISS_LINE2 = '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129';

    public function definition(): array
    {
        return [
            'satellite_id' => Satellite::factory(),
            'line1' => self::ISS_LINE1,
            'line2' => self::ISS_LINE2,
            'epoch_at' => now()->subHours(2),
            'source' => 'celestrak',
            'fetched_at' => now()->subHour(),
            'is_current' => true,
        ];
    }

    public function fresh(): static
    {
        return $this->state(['fetched_at' => now(), 'is_current' => true]);
    }

    public function stale(): static
    {
        return $this->state(['fetched_at' => now()->subHours(8), 'is_current' => false]);
    }
}
