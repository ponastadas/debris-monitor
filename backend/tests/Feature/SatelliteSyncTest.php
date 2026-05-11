<?php

use App\Models\Satellite;
use Illuminate\Support\Facades\Http;

// Minimal valid TLE lines for test fixtures.
// NORAD 25544 = ISS (PAY → satellite)
// NORAD 25545 = rocket body (R/B)
// NORAD 29228 = Fengyun 1C debris (DEB)
const ISS_TLE     = "ISS (ZARYA)\n1 25544U 98067A   26001.00000000  .00000000  00000-0  00000-0 0  9999\n2 25544  51.6400 000.0000 0001000 000.0000 000.0000 15.50000000000000";
const RB_TLE      = "SL-1 R/B\n1 00001U 58001B   26001.00000000  .00000000  00000-0  00000-0 0  9999\n2 00001  34.2500 000.0000 0010000 000.0000 000.0000 10.85000000000000";
const DEB_TLE     = "FENGYUN 1C DEB\n1 29228U 99025CJ  26001.00000000  .00000000  00000-0  00000-0 0  9999\n2 29228  98.8000 000.0000 0010000 000.0000 000.0000 14.07000000000000";
// NORAD 99998 is a made-up ID not present in the test SATCAT CSV — used to test group-level fallback.
const NOSATCAT_TLE = "UNKNOWN SAT\n1 99998U 99999A   26001.00000000  .00000000  00000-0  00000-0 0  9999\n2 99998  51.6400 000.0000 0001000 000.0000 000.0000 15.50000000000000";

// Minimal SATCAT CSV with PAY, R/B, DEB rows
const SATCAT_CSV = <<<'CSV'
OBJECT_NAME,OBJECT_ID,NORAD_CAT_ID,OBJECT_TYPE,OPS_STATUS_CODE,OWNER,LAUNCH_DATE,LAUNCH_SITE,DECAY_DATE,PERIOD,INCLINATION,APOGEE,PERIGEE,RCS,DATA_STATUS_CODE,ORBIT_CENTER,ORBIT_TYPE
ISS (ZARYA),1998-067A,25544,PAY,+,ISS,1998-11-20,TTMTR,,91.93,51.63,415,409,,,EA,IMP
SL-1 R/B,1958-001B,1,R/B,-,US,1958-01-31,AFETR,1958-03-30,,,,,,,,
FENGYUN 1C DEB,1999-025CJ,29228,DEB,-,PRC,1999-05-10,TSC,,96.78,98.81,813,787,,,EA,
CSV;

it('SATCAT-based sync classifies satellite correctly as satellite type', function () {
    Http::fake([
        'celestrak.org/pub/satcat.csv'          => Http::response(SATCAT_CSV, 200),
        'celestrak.org/NORAD/elements/gp.php*'  => Http::response(ISS_TLE, 200),
    ]);

    $this->artisan('satellites:sync', ['--groups' => 'active'])->assertSuccessful();

    $sat = Satellite::where('norad_id', '25544')->first();
    expect($sat)->not->toBeNull()
        ->and($sat->object_type)->toBe('satellite');
});

it('SATCAT-based sync classifies rocket body correctly regardless of group type', function () {
    // Group 'active' would set object_type = 'satellite', but SATCAT says R/B → rocket_body
    Http::fake([
        'celestrak.org/pub/satcat.csv'          => Http::response(SATCAT_CSV, 200),
        'celestrak.org/NORAD/elements/gp.php*'  => Http::response(RB_TLE, 200),
    ]);

    $this->artisan('satellites:sync', ['--groups' => 'active'])->assertSuccessful();

    $sat = Satellite::where('norad_id', '00001')->first();
    expect($sat)->not->toBeNull()
        ->and($sat->object_type)->toBe('rocket_body');
});

it('SATCAT-based sync classifies debris correctly', function () {
    Http::fake([
        'celestrak.org/pub/satcat.csv'          => Http::response(SATCAT_CSV, 200),
        'celestrak.org/NORAD/elements/gp.php*'  => Http::response(DEB_TLE, 200),
    ]);

    $this->artisan('satellites:sync', ['--groups' => 'active'])->assertSuccessful();

    $sat = Satellite::where('norad_id', '29228')->first();
    expect($sat)->not->toBeNull()
        ->and($sat->object_type)->toBe('debris');
});

it('falls back to group-level type when SATCAT fetch fails', function () {
    Http::fake([
        'celestrak.org/pub/satcat.csv'          => Http::response('', 503),
        'celestrak.org/NORAD/elements/gp.php*'  => Http::response(ISS_TLE, 200),
    ]);

    // Group 'active' → satellite; SATCAT unavailable → group-level fallback
    $this->artisan('satellites:sync', ['--groups' => 'active'])->assertSuccessful();

    $sat = Satellite::where('norad_id', '25544')->first();
    expect($sat)->not->toBeNull()
        ->and($sat->object_type)->toBe('satellite');
});

it('falls back to group-level type for objects missing from SATCAT', function () {
    Http::fake([
        'celestrak.org/pub/satcat.csv'          => Http::response(SATCAT_CSV, 200),
        'celestrak.org/NORAD/elements/gp.php*'  => Http::response(ISS_TLE."\n".NOSATCAT_TLE, 200),
    ]);

    // Group 'fengyun-1c-debris' → debris fallback for any NORAD not in SATCAT
    $this->artisan('satellites:sync', ['--groups' => 'fengyun-1c-debris'])->assertSuccessful();

    // ISS IS in SATCAT → PAY → satellite (overrides group debris type)
    $iss = Satellite::where('norad_id', '25544')->first();
    expect($iss->object_type)->toBe('satellite');

    // NORAD 99998 is absent from SATCAT → falls back to group type 'debris'
    $unknown = Satellite::where('norad_id', '99998')->first();
    expect($unknown->object_type)->toBe('debris');
});
