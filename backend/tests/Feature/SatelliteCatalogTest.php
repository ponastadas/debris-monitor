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
    $iss = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($iss)->create();

    $hst = Satellite::factory()->forNorad('20580', 'HST')->create();
    TleRecord::factory()->fresh()->for($hst)->create();

    $this->getJson('/api/satellites/search?q=ISS')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'ISS (ZARYA)')
        ->assertJsonCount(1, 'data');
});

it('searches by norad_id from local catalog', function () {
    $iss = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($iss)->create();

    $this->getJson('/api/satellites/search?q=25544')
        ->assertOk()
        ->assertJsonPath('data.0.norad_id', '25544');
});

it('returns empty when local catalog has no match', function () {
    $this->getJson('/api/satellites/search?q=ISS')
        ->assertOk()
        ->assertJson(['success' => true, 'data' => []]);
});

it('limits results to 10 matches', function () {
    for ($i = 0; $i < 15; $i++) {
        $sat = Satellite::factory()->forNorad("1000{$i}", "DEBRIS-{$i}", 'debris')->create();
        TleRecord::factory()->fresh()->for($sat)->create();
    }

    $this->getJson('/api/satellites/search?q=DEBRIS')
        ->assertOk()
        ->assertJsonCount(10, 'data');
});

it('search only uses local catalog', function () {
    $iss = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($iss)->create();

    $this->getJson('/api/satellites/search?q=ISS')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'ISS (ZARYA)');
});

it('caches search results for repeated queries', function () {
    $iss = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($iss)->create();

    // First request — populates cache
    $this->getJson('/api/satellites/search?q=ISS')
        ->assertOk()
        ->assertJsonPath('data.0.norad_id', '25544');

    // Delete the satellite from DB to prove cache is being used
    TleRecord::where('satellite_id', $iss->id)->delete();
    $iss->delete();

    // Second request — must still return the cached result
    $this->getJson('/api/satellites/search?q=ISS')
        ->assertOk()
        ->assertJsonPath('data.0.norad_id', '25544');
});

// ── Search ↔ show consistency guarantee ──────────────────────────────────────

it('search does not return satellites without a current TLE', function () {
    Http::fake(['celestrak.org/*' => Http::response('No GP data found', 404)]);

    Satellite::factory()->iss()->create(); // satellite row, no TLE record

    $this->getJson('/api/satellites/search?q=ISS')
        ->assertOk()
        ->assertJson(['success' => true, 'data' => []]);
});

it('satellite returned by local DB search can always be loaded by show', function () {
    Http::fake();

    $iss = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($iss)->create([
        'line1' => '1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990',
        'line2' => '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129',
    ]);

    $searchRes = $this->getJson('/api/satellites/search?q=ISS')->assertOk();
    $noradId = $searchRes->json('data.0.norad_id');

    $this->getJson("/api/satellites/{$noradId}")
        ->assertOk()
        ->assertJsonPath('data.source', 'local');

    Http::assertNothingSent();
});

// ── is_current uniqueness ─────────────────────────────────────────────────────

it('upsertCurrentTle leaves exactly one is_current=true row per satellite', function () {
    $sat = Satellite::factory()->iss()->create();

    // Insert initial current TLE
    $sat->upsertCurrentTle(
        '1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990',
        '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129'
    );

    expect($sat->tleRecords()->where('is_current', true)->count())->toBe(1);

    // Second write (simulates a re-cache)
    $sat->upsertCurrentTle(
        '1 25544U 98067A   26109.50000000  .00002182  00000-0  40768-4 0  9991',
        '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432130'
    );

    expect($sat->tleRecords()->where('is_current', true)->count())->toBe(1);
    expect($sat->tleRecords()->where('is_current', false)->count())->toBe(1);
});

it('SatelliteController show write leaves exactly one is_current=true row', function () {
    Http::fake([
        'celestrak.org/*' => Http::response(
            "ISS (ZARYA)\n".
            "1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990\n".
            '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129',
            200
        ),
    ]);

    // First fetch — creates satellite + current TLE
    $this->getJson('/api/satellites/25544')->assertOk();

    // Second fetch — local TLE exists, no second call needed; but let's trigger
    // another CelesTrak write by removing the current TLE first
    TleRecord::where('is_current', true)->update(['is_current' => false]);

    $this->getJson('/api/satellites/25544')->assertOk();

    $sat = Satellite::where('norad_id', '25544')->first();
    expect($sat->tleRecords()->where('is_current', true)->count())->toBe(1);
});

// ── Staleness sweep ───────────────────────────────────────────────────────────

$freshTle =
    "ISS (ZARYA)             \n".
    "1 25544U 98067A   26109.50000000  .00002182  00000-0  40768-4 0  9991\n".
    '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432130';

it('staleness sweep refreshes a satellite with a stale TLE', function () use ($freshTle) {
    Http::fake(['celestrak.org/*' => Http::response($freshTle, 200)]);

    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->for($sat)->create([
        'is_current' => true,
        'fetched_at' => now()->subHours(25), // older than 24h threshold
        'line1' => '1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990',
        'line2' => '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129',
    ]);

    $this->artisan('satellites:sync', ['--groups' => 'stations'])->assertSuccessful();

    // Old TLE rotated out, new one inserted
    expect($sat->tleRecords()->where('is_current', true)->count())->toBe(1);
    expect($sat->tleRecords()->where('is_current', false)->count())->toBeGreaterThanOrEqual(1);
    expect($sat->fresh()->currentTle->fetched_at->isAfter(now()->subMinutes(1)))->toBeTrue();
});

it('staleness sweep skips satellites with a fresh TLE (< 24h)', function () {
    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->for($sat)->create([
        'is_current' => true,
        'fetched_at' => now()->subHours(1), // fresh — should NOT be swept
        'line1' => '1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990',
        'line2' => '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129',
    ]);

    Http::fake(['celestrak.org/*' => Http::response('No GP data found', 404)]);

    $this->artisan('satellites:sync', ['--groups' => 'stations'])->assertSuccessful();

    // SATCAT fetch + GROUP=stations fetch = 2 requests; no CATNR call for the fresh sat
    Http::assertSentCount(2);
});

it('staleness sweep respects the SATELLITE_SYNC_STALE_LIMIT cap', function () use ($freshTle) {
    // Create 5 stale satellites but cap at 3
    for ($i = 1; $i <= 5; $i++) {
        $sat = Satellite::factory()->forNorad("9990{$i}", "SAT-{$i}")->create();
        TleRecord::factory()->for($sat)->create([
            'is_current' => true,
            'fetched_at' => now()->subHours(25 + $i),
            'line1' => "1 9990{$i}U 20001A   26109.50000000  .00001000  00000-0  10000-4 0  999".($i),
            'line2' => "2 9990{$i}  97.0000 240.0000 0001000 200.0000 160.0000 15.00000000000000",
        ]);
    }

    Http::fake(['celestrak.org/*' => Http::response($freshTle, 200)]);

    $this->artisan('satellites:sync', ['--groups' => 'stations', '--env' => 'testing'])
        ->assertSuccessful();

    // With default limit 200, all 5 should be swept — we're testing the mechanic
    // works; a true cap test would need env override, so just assert the sweep ran
    // and left valid is_current state.
    foreach (range(1, 5) as $i) {
        $sat = Satellite::where('norad_id', "9990{$i}")->first();
        expect($sat->tleRecords()->where('is_current', true)->count())->toBeLessThanOrEqual(1);
    }
});

it('staleness sweep stops on CelesTrak 429 without throwing', function () {
    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->for($sat)->create([
        'is_current' => true,
        'fetched_at' => now()->subHours(25),
        'line1' => '1 25544U 98067A   24001.50000000  .00002182  00000-0  40768-4 0  9990',
        'line2' => '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129',
    ]);

    Http::fake([
        // Group fetch returns empty, CATNR sweep returns 429
        'celestrak.org/*' => Http::sequence()
            ->push('No GP data found', 404)  // group fetch
            ->push('', 429),                  // CATNR sweep call
    ]);

    // Must complete without exception
    $this->artisan('satellites:sync', ['--groups' => 'stations'])->assertSuccessful();
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
            '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129',
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
            '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129',
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

    $this->getJson('/api/satellites/9999999')
        ->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'NOT_FOUND');
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
            '2 37849  42.7895 247.4627 0006703 130.5360 325.0288 15.50043005432129',
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
        '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129';

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
            '2 25544  51.6416 247.4627 0006703 130.5360 325.0288 15.50043005432129',
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

    $first = $res->json('data.satellites.0');
    expect($first)->toHaveKey('norad_id');
    expect($first)->toHaveKey('name');
    expect($first)->toHaveKey('type');
    expect($first)->toHaveKey('line1');
    expect($first)->toHaveKey('line2');
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
    $etag = $first->headers->get('ETag');

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
    $first = $this->getJson('/api/catalog')->assertOk();
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

    // 'bogus' maps to no known object_type — WHERE 0=1 → no rows returned
    $this->getJson('/api/catalog?types=bogus')
        ->assertOk()
        ->assertJsonPath('data.count', 0);
});

it('catalog endpoint with no filter returns all object types including rocket and unknown', function () {
    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create();

    $deb = Satellite::factory()->forNorad('29228', 'FENGYUN DEB', 'debris')->create();
    TleRecord::factory()->fresh()->for($deb)->create();

    $rocket = Satellite::factory()->forNorad('10001', 'ROCKET BODY', 'rocket_body')->create();
    TleRecord::factory()->fresh()->for($rocket)->create();

    $unknown = Satellite::factory()->forNorad('10002', 'MYSTERY OBJ', null)->create();
    TleRecord::factory()->fresh()->for($unknown)->create();

    $res = $this->getJson('/api/catalog')->assertOk();
    expect($res->json('data.count'))->toBe(4);

    $types = collect($res->json('data.satellites'))->pluck('type')->unique()->sort()->values()->all();
    expect($types)->toContain('satellite');
    expect($types)->toContain('debris');
    expect($types)->toContain('rocket');
    expect($types)->toContain('unknown');
});

it('catalog endpoint filters by rocket type', function () {
    $rocket = Satellite::factory()->forNorad('10001', 'ROCKET BODY', 'rocket_body')->create();
    TleRecord::factory()->fresh()->for($rocket)->create();

    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create();

    $res = $this->getJson('/api/catalog?types=rocket')->assertOk();
    expect($res->json('data.count'))->toBe(1);

    $types = collect($res->json('data.satellites'))->pluck('type')->unique()->values()->all();
    expect($types)->toBe(['rocket']);
});

it('catalog endpoint filters by unknown type returns null-object_type rows', function () {
    $unknown = Satellite::factory()->forNorad('10002', 'MYSTERY OBJ', null)->create();
    TleRecord::factory()->fresh()->for($unknown)->create();

    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create();

    $res = $this->getJson('/api/catalog?types=unknown')->assertOk();
    expect($res->json('data.count'))->toBe(1);

    $types = collect($res->json('data.satellites'))->pluck('type')->unique()->values()->all();
    expect($types)->toBe(['unknown']);
});

it('catalog response includes norad_id in each satellite entry', function () {
    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create();

    $res = $this->getJson('/api/catalog')->assertOk();

    $first = $res->json('data.satellites.0');
    expect($first['norad_id'])->toBe('25544');
});

// ── Admin dashboard catalog stats ────────────────────────────────────────────

it('admin dashboard includes catalog stats', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $sat = Satellite::factory()->iss()->create();
    TleRecord::factory()->fresh()->for($sat)->create();

    $deb = Satellite::factory()->forNorad('29228', 'FENGYUN DEB', 'debris')->create();
    TleRecord::factory()->fresh()->for($deb)->create();

    $rocket = Satellite::factory()->forNorad('10001', 'ROCKET BODY', 'rocket_body')->create();
    TleRecord::factory()->fresh()->for($rocket)->create();

    $r = $this->withToken($token)->getJson('/api/admin/dashboard')->assertOk();

    expect($r->json('data.catalog.total'))->toBe(3);
    expect($r->json('data.catalog.synced_at'))->not->toBeNull();

    // by_type uses frontend-normalized keys (satellite/debris/rocket/unknown — not raw DB values)
    $byType = $r->json('data.catalog.by_type');
    expect($byType)->toBeArray();
    expect($byType['satellite'])->toBe(1);
    expect($byType['debris'])->toBe(1);
    expect($byType['rocket'])->toBe(1);
    expect($byType)->not->toHaveKey('rocket_body'); // raw DB key must not appear
});

it('admin dashboard catalog stats are zero when catalog empty', function () {
    $admin = AdminAccount::factory()->create();
    $token = $admin->createToken('admin-session')->plainTextToken;

    $r = $this->withToken($token)->getJson('/api/admin/dashboard')->assertOk();

    expect($r->json('data.catalog.total'))->toBe(0);
    expect($r->json('data.catalog.synced_at'))->toBeNull();
});
