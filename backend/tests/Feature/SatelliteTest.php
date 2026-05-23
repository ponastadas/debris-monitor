<?php

use App\Models\Satellite;
use App\Models\TleRecord;
use Illuminate\Support\Facades\Http;

it('returns 404 for unknown norad id', function () {
    Http::fake([
        'celestrak.org/*' => Http::response('No GP data found', 200),
    ]);

    $this->getJson('/api/satellites/9999999')
        ->assertNotFound();
});

it('returns satellite data for valid norad id from local DB', function () {
    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create();

    $this->getJson('/api/satellites/25544')
        ->assertOk()
        ->assertJsonStructure(['success', 'data' => ['norad_id', 'name', 'tle_line1', 'tle_line2']]);
});

it('returns satellite data via celestrak fallback when not in DB', function () {
    Http::fake([
        'celestrak.org/*' => Http::response(
            "ISS (ZARYA)\n1 25544U 98067A   24001.00000000  .00000000  00000-0  00000-0 0  9999\n2 25544  51.6400 000.0000 0001000 000.0000 000.0000 15.50000000000000",
            200
        ),
    ]);

    $this->getJson('/api/satellites/25544')
        ->assertOk()
        ->assertJsonStructure(['success', 'data' => ['norad_id', 'name', 'tle_line1', 'tle_line2']]);
});
