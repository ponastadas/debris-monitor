<?php

use App\Models\AdminAccount;
use App\Models\Satellite;
use App\Models\TleRecord;
use Illuminate\Support\Facades\Http;

// ── Search endpoint ───────────────────────────────────────────────────────────

it('returns empty array for short queries', function () {
    $this->getJson('/api/satellites/search?q=I')
         ->assertOk()
         ->assertJson(['success' => true, 'data' => []]);
});

it('searches by name from local catalog', function () {
    Satellite::factory()->iss()->create();
    Satellite::factory()->forNorad('20580', 'HST')->create();

    $this->getJson('/api/satellites/search?q=ISS')
         ->assertOk()
         ->assertJsonPath('data.0.name', 'ISS (ZARYA)')
         ->assertJsonCount(1, 'data');
});

it('searches by norad_id from local catalog', function () {
    Satellite::factory()->iss()->create();

    $this->getJson('/api/satellites/search?q=25544')
         ->assertOk()
         ->assertJsonPath('data.0.norad_id', '25544');
});

it('returns empty when catalog is empty and celestrak has no match', function () {
    Http::fake([
        'celestrak.org/*' => Http::response('No GP data found', 404),
    ]);

    $this->getJson('/api/satellites/search?q=ISS')
         ->assertOk()
         ->assertJson(['success' => true, 'data' => []]);
});

it('limits results to 10 matches', function () {
    for ($i = 0; $i < 15; $i++) {
        Satellite::factory()->forNorad("1000{$i}", "DEBRIS-{$i}", 'debris')->create();
    }

    $this->getJson('/api/satellites/search?q=DEBRIS')
         ->assertOk()
         ->assertJsonCount(10, 'data');
});

it('does not call celestrak on search when local result found', function () {
    Http::fake();

    Satellite::factory()->iss()->create();

    $this->getJson('/api/satellites/search?q=ISS')->assertOk();

    Http::assertNothingSent();
});

// ── Live CelesTrak fallback (satellites absent from local catalog) ─────────────

$horacioTle =
    "HORACIO                 \n".
    "1 59098U 24043A   26109.52124740  .00002200  00000+0  18994-3 0  9994\n".
    "2 59098  97.8434 240.9608 0009670 206.7984 153.2740 14.98275414115885";

$r2Tle =
    "R2                      \n".
    "1 46913U 20081J   26109.56051816  .00002247  00000+0  17722-3 0  9991\n".
    "2 46913  36.8986 212.3400 0009670 206.7984 153.2740 15.12345678901234";

it('finds HORACIO by name via live CelesTrak fallback', function () use ($horacioTle) {
    Http::fake(['celestrak.org/*' => Http::response($horacioTle, 200)]);

    $this->getJson('/api/satellites/search?q=horacio')
         ->assertOk()
         ->assertJsonPath('data.0.norad_id', '59098')
         ->assertJsonPath('data.0.name', 'HORACIO');
});

it('finds HORACIO by NORAD ID via live CelesTrak fallback', function () use ($horacioTle) {
    Http::fake(['celestrak.org/*' => Http::response($horacioTle, 200)]);

    $this->getJson('/api/satellites/search?q=59098')
         ->assertOk()
         ->assertJsonPath('data.0.norad_id', '59098');
});

it('finds HORACIO by international designator via live CelesTrak fallback', function () use ($horacioTle) {
    Http::fake(['celestrak.org/*' => Http::response($horacioTle, 200)]);

    $this->getJson('/api/satellites/search?q=2024-043A')
         ->assertOk()
         ->assertJsonPath('data.0.norad_id', '59098');
});

it('finds R2 by name via live CelesTrak fallback', function () use ($r2Tle) {
    Http::fake(['celestrak.org/*' => Http::response($r2Tle, 200)]);

    $this->getJson('/api/satellites/search?q=r2')
         ->assertOk()
         ->assertJsonPath('data.0.norad_id', '46913')
         ->assertJsonPath('data.0.name', 'R2');
});

it('finds R2 by NORAD ID via live CelesTrak fallback', function () use ($r2Tle) {
    Http::fake(['celestrak.org/*' => Http::response($r2Tle, 200)]);

    $this->getJson('/api/satellites/search?q=46913')
         ->assertOk()
         ->assertJsonPath('data.0.norad_id', '46913');
});

it('finds R2 by international designator via live CelesTrak fallback', function () use ($r2Tle) {
    Http::fake(['celestrak.org/*' => Http::response($r2Tle, 200)]);

    $this->getJson('/api/satellites/search?q=2020-081J')
         ->assertOk()
         ->assertJsonPath('data.0.norad_id', '46913');
});

it('caches satellite to local DB after live CelesTrak fallback search', function () use ($horacioTle) {
    Http::fake(['celestrak.org/*' => Http::response($horacioTle, 200)]);

    $this->getJson('/api/satellites/search?q=59098')->assertOk();

    expect(Satellite::where('norad_id', '59098')->exists())->toBeTrue();
});

// ── Satellite show endpoint ───────────────────────────────────────────────────

it('returns TLE from local DB without calling celestrak', function () {
    Http::fake();

    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create([
        'line1' => '1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990',
        'line2' => '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129',
    ]);

    $this->getJson('/api/satellites/25544')
         ->assertOk()
         ->assertJsonPath('data.norad_id', '25544')
         ->assertJsonPath('data.source', 'local')
         ->assertJsonStructure(['success', 'data' => ['norad_id', 'name', 'tle_line1', 'tle_line2', 'source']]);

    Http::assertNothingSent();
});

it('falls back to celestrak when satellite not in local DB', function () {
    Http::fake([
        'celestrak.org/*' => Http::response(
            "ISS (ZARYA)\n".
            "1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990\n".
            "2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129",
            200
        ),
    ]);

    $this->getJson('/api/satellites/25544')
         ->assertOk()
         ->assertJsonPath('data.source', 'celestrak')
         ->assertJsonPath('data.norad_id', '25544');

    Http::assertSentCount(1);
});

it('caches the TLE after a fallback celestrak fetch', function () {
    Http::fake([
        'celestrak.org/*' => Http::response(
            "ISS (ZARYA)\n".
            "1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990\n".
            "2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129",
            200
        ),
    ]);

    // First request — fetches from CelesTrak and caches
    $this->getJson('/api/satellites/25544')->assertOk();

    expect(Satellite::where('norad_id', '25544')->exists())->toBeTrue();
    expect(TleRecord::whereHas('satellite', fn ($q) => $q->where('norad_id', '25544'))->where('is_current', true)->exists())->toBeTrue();
});

it('returns 404 for unknown norad id from celestrak', function () {
    Http::fake([
        'celestrak.org/*' => Http::response('No GP data found', 200),
    ]);

    $this->getJson('/api/satellites/9999999')->assertNotFound();
});

it('serves stale local TLE even when celestrak is unreachable', function () {
    // is_current=true records are always served regardless of age — the staleness
    // check was removed so the Tracker never fails due to a cold CelesTrak call.
    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->for($sat)->create([
        'fetched_at' => now()->subHours(8),
        'is_current' => true,
        'line1' => '1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990',
        'line2' => '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129',
    ]);

    Http::fake([
        'celestrak.org/*' => Http::response('', 503),
    ]);

    $this->getJson('/api/satellites/25544')
        ->assertOk()
        ->assertJsonPath('data.source', 'local');
});

// ── Satellites:sync command ───────────────────────────────────────────────────

it('parses and inserts satellites from TLE response', function () {
    Http::fake([
        'celestrak.org/*' => Http::response(
            "ISS (ZARYA)\n".
            "1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990\n".
            "2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129\n".
            "TIANGONG-1\n".
            "1 37849U 11053A   24001.50000000  .00002182  00000-0  40768-4 0  9990\n".
            "2 37849  42.7895 247.4627 0006703 130.5360 325.0288 15.50043005432129",
            200
        ),
    ]);

    $this->artisan('satellites:sync', ['--groups' => 'stations'])
         ->assertSuccessful();

    expect(Satellite::count())->toBe(2);
    expect(TleRecord::where('is_current', true)->count())->toBe(2);
});

it('is idempotent — running twice does not duplicate satellites', function () {
    $tleBody = "ISS (ZARYA)\n".
        "1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990\n".
        "2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129";

    Http::fake(['celestrak.org/*' => Http::response($tleBody, 200)]);

    $this->artisan('satellites:sync', ['--groups' => 'stations'])->assertSuccessful();
    $this->artisan('satellites:sync', ['--groups' => 'stations'])->assertSuccessful();

    expect(Satellite::count())->toBe(1);
    expect(TleRecord::where('is_current', true)->count())->toBe(1);
    expect(TleRecord::where('is_current', false)->count())->toBe(1); // previous one archived
});

it('dry-run does not write to DB', function () {
    Http::fake([
        'celestrak.org/*' => Http::response(
            "ISS (ZARYA)\n".
            "1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990\n".
            "2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129",
            200
        ),
    ]);

    $this->artisan('satellites:sync', ['--groups' => 'stations', '--dry-run' => true])
         ->assertSuccessful();

    expect(Satellite::count())->toBe(0);
    expect(TleRecord::count())->toBe(0);
});

it('skips unavailable groups gracefully', function () {
    Http::fake(['celestrak.org/*' => Http::response('', 503)]);

    $this->artisan('satellites:sync', ['--groups' => 'stations'])
         ->assertSuccessful(); // command itself succeeds, just logs a warning

    expect(Satellite::count())->toBe(0);
});

// ── GET /api/catalog ──────────────────────────────────────────────────────────

it('catalog endpoint returns empty when catalog not synced', function () {
    $this->getJson('/api/catalog')
         ->assertOk()
         ->assertJsonPath('data.count', 0)
         ->assertJsonPath('data.satellites', []);
});

it('catalog endpoint returns satellites with TLE data', function () {
    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create([
        'line1' => '1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990',
        'line2' => '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129',
    ]);

    Satellite::factory()->debris()->forNorad('29228', 'FENGYUN 1C DEB', 'debris')->create();
    TleRecord::factory()->fresh()->for(Satellite::where('norad_id', '29228')->first())->create();

    $res = $this->getJson('/api/catalog')
         ->assertOk()
         ->assertJsonPath('data.count', 2)
         ->assertJsonStructure(['data' => ['satellites', 'count', 'synced_at']]);

    // norad_id is no longer returned — frontend extracts it from line1[2:7]
    $first = $res->json('data.satellites.0');
    expect($first)->toHaveKey('name');
    expect($first)->toHaveKey('type');
    expect($first)->toHaveKey('line1');
    expect($first)->toHaveKey('line2');
    expect($first)->not->toHaveKey('norad_id');
});

it('catalog endpoint maps rocket_body type to rocket for frontend', function () {
    $sat = Satellite::factory()->forNorad('99001', 'ROCKET STAGE', 'rocket_body')->create();
    TleRecord::factory()->fresh()->for($sat)->create();

    $res = $this->getJson('/api/catalog')->assertOk();

    $types = collect($res->json('data.satellites'))->pluck('type')->unique()->values()->all();
    expect($types)->toContain('rocket');
    expect($types)->not->toContain('rocket_body');
});

it('catalog endpoint does not include satellites without current TLE', function () {
    Satellite::factory()->iss()->create(); // no TLE record

    $this->getJson('/api/catalog')
         ->assertOk()
         ->assertJsonPath('data.count', 0);
});

it('catalog endpoint returns cache-control header', function () {
    $this->getJson('/api/catalog')
         ->assertOk()
         ->assertHeader('Cache-Control', 'max-age=3600, public');
});

it('catalog endpoint returns an etag header', function () {
    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create();

    $res = $this->getJson('/api/catalog')->assertOk();
    expect($res->headers->has('ETag'))->toBeTrue();
    expect($res->headers->get('ETag'))->toMatch('/^"[a-f0-9]{32}"$/');
});

it('catalog endpoint returns 304 when etag matches', function () {
    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create();

    // First request — get the ETag
    $first = $this->getJson('/api/catalog')->assertOk();
    $etag  = $first->headers->get('ETag');

    // Second request with matching If-None-Match — expect 304
    $this->withHeaders(['If-None-Match' => $etag])
         ->get('/api/catalog')
         ->assertStatus(304);
});

it('catalog endpoint returns 200 when etag does not match', function () {
    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create();

    $this->withHeaders(['If-None-Match' => '"stale-etag-value"'])
         ->getJson('/api/catalog')
         ->assertOk();
});

it('catalog endpoint empty catalog etag is stable', function () {
    $first  = $this->getJson('/api/catalog')->assertOk();
    $second = $this->getJson('/api/catalog')->assertOk();
    expect($first->headers->get('ETag'))->toBe($second->headers->get('ETag'));
});

it('catalog endpoint filters by single type', function () {
    $sat1 = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat1)->create();

    $deb = Satellite::factory()->debris()->forNorad('29228', 'FENGYUN DEB', 'debris')->create();
    TleRecord::factory()->fresh()->for($deb)->create();

    $res = $this->getJson('/api/catalog?types=satellite')->assertOk();
    expect($res->json('data.count'))->toBe(1);

    $types = collect($res->json('data.satellites'))->pluck('type')->unique()->all();
    expect($types)->toBe(['satellite']);
});

it('catalog endpoint filters by multiple types', function () {
    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create();

    $deb = Satellite::factory()->debris()->forNorad('29228', 'FENGYUN DEB', 'debris')->create();
    TleRecord::factory()->fresh()->for($deb)->create();

    $rocket = Satellite::factory()->forNorad('10001', 'ROCKET STAGE', 'rocket_body')->create();
    TleRecord::factory()->fresh()->for($rocket)->create();

    // Request satellite + debris only
    $res = $this->getJson('/api/catalog?types=satellite,debris')->assertOk();
    expect($res->json('data.count'))->toBe(2);

    $types = collect($res->json('data.satellites'))->pluck('type')->unique()->sort()->values()->all();
    expect($types)->toBe(['debris', 'satellite']);
});

it('catalog endpoint ignores unknown type tokens gracefully', function () {
    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create();

    // 'bogus' maps to no known object_type — WHERE IN () → no rows returned
    $this->getJson('/api/catalog?types=bogus')
         ->assertOk()
         ->assertJsonPath('data.count', 0);
});

// ── Admin dashboard catalog stats ────────────────────────────────────────────

it('admin dashboard includes catalog stats', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create();

    $r = $this->withToken($token)->getJson('/api/admin/dashboard')->assertOk();

    expect($r->json('data.catalog.total'))->toBe(1);
    expect($r->json('data.catalog.synced_at'))->not->toBeNull();
    expect($r->json('data.catalog.by_type'))->toBeArray();
});

it('admin dashboard catalog stats are zero when catalog empty', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $r = $this->withToken($token)->getJson('/api/admin/dashboard')->assertOk();

    expect($r->json('data.catalog.total'))->toBe(0);
    expect($r->json('data.catalog.synced_at'))->toBeNull();
});
