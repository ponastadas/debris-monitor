<?php

namespace Database\Factories;

use App\Models\Satellite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Satellite>
 */
class SatelliteFactory extends Factory
{
    protected $model = Satellite::class;

    private const SAMPLES = [
        ['norad_id' => '25544', 'name' => 'ISS (ZARYA)',         'object_type' => 'satellite'],
        ['norad_id' => '20580', 'name' => 'HST',                 'object_type' => 'satellite'],
        ['norad_id' => '43013', 'name' => 'GOES-16',             'object_type' => 'satellite'],
        ['norad_id' => '37849', 'name' => 'TIANGONG-1',          'object_type' => 'satellite'],
        ['norad_id' => '29228', 'name' => 'FENGYUN 1C DEB',      'object_type' => 'debris'],
        ['norad_id' => '33764', 'name' => 'COSMOS 2251 DEB',     'object_type' => 'debris'],
        ['norad_id' => '33438', 'name' => 'IRIDIUM 33 DEB',      'object_type' => 'debris'],
    ];

    public function definition(): array
    {
        $sample = fake()->randomElement(self::SAMPLES);

        return [
            'norad_id' => $sample['norad_id'],
            'name' => $sample['name'],
            'object_type' => $sample['object_type'],
            'is_active' => $sample['object_type'] === 'satellite',
            'catalog_source' => 'celestrak',
            'last_seen_at' => now(),
        ];
    }

    public function iss(): static
    {
        return $this->state(['norad_id' => '25544', 'name' => 'ISS (ZARYA)', 'object_type' => 'satellite', 'is_active' => true]);
    }

    public function debris(): static
    {
        return $this->state(['object_type' => 'debris', 'is_active' => false]);
    }

    public function forNorad(string $noradId, string $name, ?string $type = 'satellite'): static
    {
        return $this->state(['norad_id' => $noradId, 'name' => $name, 'object_type' => $type]);
    }
}
