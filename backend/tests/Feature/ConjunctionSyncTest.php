<?php

use App\Models\ConjunctionAlert;
use App\Models\ConjunctionEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WatchedSatellite;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Fake CDM record matching the Space-Track CDM_PUBLIC JSON shape.
 */
function fakeCdmRecord(array $overrides = []): array
{
    static $counter = 1_000_000;
    $counter++;

    return array_merge([
        'CDM_ID' => (string) $counter,
        'CREATED' => now()->subHours(6)->format('Y-m-d H:i:s'),
        'EMERGENCY_REPORTABLE' => 'N',
        'TCA' => now()->addDays(2)->format('Y-m-d H:i:s'),
        'MIN_RNG' => '1.234',
        'PC' => '1.23456e-05',
        'SAT_1_NAME' => 'ISS (ZARYA)',
        'SAT_1_ID' => '25544',
        'SAT_2_NAME' => 'FENGYUN 1C DEB',
        'SAT_2_ID' => '29228',
    ], $overrides);
}

/** Configure Space-Track credentials in the test config. */
function withSpaceTrackCreds(): void
{
    Config::set('services.space_track.user', 'test@example.com');
    Config::set('services.space_track.pass', 'test-pass');
}

/** Set up Http::fake() to simulate a successful Space-Track login + CDM response. */
function fakeSpaceTrack(array $cdmRecords): void
{
    Http::fake([
        'www.space-track.org/ajaxauth/login' => Http::response('', 200),
        'www.space-track.org/basicspacedata/*' => Http::response($cdmRecords, 200),
    ]);
}

// ── conjunctions:sync command — no credentials ────────────────────────────────

it('exits cleanly when Space-Track credentials are not configured', function () {
    Config::set('services.space_track.user', null);
    Config::set('services.space_track.pass', null);

    $this->artisan('conjunctions:sync')->assertSuccessful();

    expect(ConjunctionEvent::count())->toBe(0);
});

it('dry-run skips DB writes when credentials are set', function () {
    withSpaceTrackCreds();
    fakeSpaceTrack([fakeCdmRecord()]);

    $this->artisan('conjunctions:sync', ['--dry-run' => true])->assertSuccessful();

    expect(ConjunctionEvent::count())->toBe(0);
    expect(ConjunctionAlert::count())->toBe(0);
});

// ── CDM ingestion ─────────────────────────────────────────────────────────────

it('upserts CDM events by cdm_id', function () {
    withSpaceTrackCreds();
    fakeSpaceTrack([fakeCdmRecord(['CDM_ID' => '99001'])]);

    $this->artisan('conjunctions:sync')->assertSuccessful();

    expect(ConjunctionEvent::count())->toBe(1);
    $e = ConjunctionEvent::first();
    expect($e->cdm_id)->toBe('99001')
        ->and($e->sat1_norad_id)->toBe('25544')
        ->and($e->sat2_norad_id)->toBe('29228')
        ->and($e->source)->toBe('space_track_cdm')
        ->and($e->probability)->toBeFloat();
});

it('is idempotent — running twice does not duplicate events', function () {
    withSpaceTrackCreds();
    fakeSpaceTrack([fakeCdmRecord(['CDM_ID' => '99002'])]);

    $this->artisan('conjunctions:sync')->assertSuccessful();
    $this->artisan('conjunctions:sync')->assertSuccessful();

    expect(ConjunctionEvent::count())->toBe(1);
});

it('ingests multiple CDM events in one run', function () {
    withSpaceTrackCreds();
    fakeSpaceTrack([
        fakeCdmRecord(['CDM_ID' => '99003']),
        fakeCdmRecord(['CDM_ID' => '99004', 'SAT_1_ID' => '20580', 'SAT_1_NAME' => 'HST']),
        fakeCdmRecord(['CDM_ID' => '99005', 'SAT_1_ID' => '43013', 'SAT_1_NAME' => 'GOES-16']),
    ]);

    $this->artisan('conjunctions:sync')->assertSuccessful();

    expect(ConjunctionEvent::count())->toBe(3);
});

it('parses probability correctly from scientific notation', function () {
    withSpaceTrackCreds();
    fakeSpaceTrack([fakeCdmRecord(['CDM_ID' => '99006', 'PC' => '1.23456e-05'])]);

    $this->artisan('conjunctions:sync')->assertSuccessful();

    $e = ConjunctionEvent::first();
    expect($e->probability)->toBeGreaterThan(0.0)
        ->and($e->probability)->toBeLessThan(0.001);
});

it('sets emergency_reportable from EMERGENCY_REPORTABLE field', function () {
    withSpaceTrackCreds();
    fakeSpaceTrack([
        fakeCdmRecord(['CDM_ID' => '99007', 'EMERGENCY_REPORTABLE' => 'Y']),
        fakeCdmRecord(['CDM_ID' => '99008', 'EMERGENCY_REPORTABLE' => 'N']),
    ]);

    $this->artisan('conjunctions:sync')->assertSuccessful();

    expect(ConjunctionEvent::where('emergency_reportable', true)->count())->toBe(1)
        ->and(ConjunctionEvent::where('emergency_reportable', false)->count())->toBe(1);
});

it('skips records with missing CDM_ID gracefully', function () {
    withSpaceTrackCreds();
    fakeSpaceTrack([
        ['TCA' => now()->addDays(2)->format('Y-m-d H:i:s')], // malformed — no CDM_ID
        fakeCdmRecord(['CDM_ID' => '99009']),
    ]);

    $this->artisan('conjunctions:sync')->assertSuccessful();

    expect(ConjunctionEvent::count())->toBe(1);
});

// ── Alert generation from CDM ─────────────────────────────────────────────────

it('creates conjunction_alert when sat1 is watched', function () {
    withSpaceTrackCreds();
    fakeSpaceTrack([fakeCdmRecord(['CDM_ID' => '99010'])]);

    $user = User::factory()->create();
    Subscription::factory()->create(['user_id' => $user->id, 'plan' => 'starter']);
    WatchedSatellite::factory()->iss()->create(['user_id' => $user->id]);

    $this->artisan('conjunctions:sync')->assertSuccessful();

    $alert = ConjunctionAlert::where('primary_norad_id', '25544')->first();
    expect($alert)->not->toBeNull()
        ->and($alert->source)->toBe('space_track_cdm')
        ->and($alert->conjunction_event_id)->not->toBeNull()
        ->and($alert->secondary_norad_id)->toBe('29228');
});

it('creates conjunction_alert when sat2 is watched', function () {
    withSpaceTrackCreds();
    fakeSpaceTrack([fakeCdmRecord(['CDM_ID' => '99011'])]);

    // Watch the secondary satellite (FENGYUN 1C DEB — NORAD 29228).
    $user = User::factory()->create();
    Subscription::factory()->create(['user_id' => $user->id, 'plan' => 'starter']);
    WatchedSatellite::factory()->forNorad('29228', 'FENGYUN 1C DEB')->create(['user_id' => $user->id]);

    $this->artisan('conjunctions:sync')->assertSuccessful();

    $alert = ConjunctionAlert::where('primary_norad_id', '29228')->first();
    expect($alert)->not->toBeNull()
        ->and($alert->secondary_norad_id)->toBe('25544')
        ->and($alert->source)->toBe('space_track_cdm');
});

it('does not create an alert for an unwatched satellite', function () {
    withSpaceTrackCreds();
    fakeSpaceTrack([fakeCdmRecord(['CDM_ID' => '99012'])]);

    // No watched satellites.
    $this->artisan('conjunctions:sync')->assertSuccessful();

    expect(ConjunctionAlert::count())->toBe(0);
});

it('does not duplicate alerts from re-running sync', function () {
    withSpaceTrackCreds();
    fakeSpaceTrack([fakeCdmRecord(['CDM_ID' => '99013'])]);

    $user = User::factory()->create();
    Subscription::factory()->create(['user_id' => $user->id, 'plan' => 'starter']);
    WatchedSatellite::factory()->iss()->create(['user_id' => $user->id]);

    $this->artisan('conjunctions:sync')->assertSuccessful();
    $this->artisan('conjunctions:sync')->assertSuccessful();

    expect(ConjunctionAlert::where('primary_norad_id', '25544')->count())->toBe(1);
});

it('alert source is space_track_cdm', function () {
    withSpaceTrackCreds();
    fakeSpaceTrack([fakeCdmRecord(['CDM_ID' => '99014'])]);

    $user = User::factory()->create();
    Subscription::factory()->create(['user_id' => $user->id, 'plan' => 'starter']);
    WatchedSatellite::factory()->iss()->create(['user_id' => $user->id]);

    $this->artisan('conjunctions:sync')->assertSuccessful();

    $alert = ConjunctionAlert::first();
    expect($alert->source)->toBe('space_track_cdm');
});

// ── ConjunctionController — real CDM data path ────────────────────────────────

it('GET /api/conjunctions returns real CDM data when events exist', function () {
    // Seed a real CDM event for ISS.
    ConjunctionEvent::factory()->forPrimary('25544', 'ISS (ZARYA)')->create();

    $res = $this->getJson('/api/conjunctions/25544')->assertOk();

    expect($res->json('data.source'))->toBe('space_track_cdm')
        ->and($res->json('data.objects'))->not->toBeEmpty();
});

it('GET /api/conjunctions returns simulated when no CDM events exist', function () {
    $res = $this->getJson('/api/conjunctions/25544')->assertOk();

    expect($res->json('data.source'))->toBe('simulated');
});

it('GET /api/conjunctions CDM response includes required fields', function () {
    ConjunctionEvent::factory()->forPrimary('25544', 'ISS (ZARYA)')->create();

    $obj = $this->getJson('/api/conjunctions/25544')->json('data.objects.0');

    expect($obj)->toHaveKeys(['object_id', 'secondary_norad_id', 'miss_km', 'probability', 'risk_score', 'risk_level', 'tca']);
    expect($obj['object_id'])->toStartWith('CDM-');
});

it('GET /api/conjunctions CDM risk_level is derived correctly', function () {
    ConjunctionEvent::factory()->high()->forPrimary('25544', 'ISS (ZARYA)')->create();
    ConjunctionEvent::factory()->low()->forPrimary('25544', 'ISS (ZARYA)')->create();

    $objects = $this->getJson('/api/conjunctions/25544')->json('data.objects');
    $levels = collect($objects)->pluck('risk_level')->unique()->values()->all();

    // At minimum we expect HIGH (from the high() state event).
    expect($levels)->toContain('HIGH');
});

it('GET /api/conjunctions ignores past CDM events', function () {
    // A past event should not appear in the response.
    ConjunctionEvent::factory()->past()->forPrimary('25544', 'ISS (ZARYA)')->create();

    $res = $this->getJson('/api/conjunctions/25544')->assertOk();

    // Falls back to simulated because the only CDM event is outside the active window.
    expect($res->json('data.source'))->toBe('simulated');
});

it('GET /api/conjunctions respects secondary satellite perspective', function () {
    // Event: ISS (sat1) ↔ Fengyun DEB (sat2)
    ConjunctionEvent::factory()->create([
        'sat1_norad_id' => '25544',
        'sat1_name' => 'ISS (ZARYA)',
        'sat2_norad_id' => '29228',
        'sat2_name' => 'FENGYUN 1C DEB',
    ]);

    // When queried from the Fengyun perspective, ISS should appear as secondary.
    $objects = $this->getJson('/api/conjunctions/29228')->json('data.objects');

    expect($objects[0]['secondary_norad_id'])->toBe('25544');
});

// ── ConjunctionEvent model helpers ────────────────────────────────────────────

it('ConjunctionEvent riskScore is HIGH for very close miss', function () {
    $event = ConjunctionEvent::factory()->high()->make();
    expect($event->riskScore())->toBeGreaterThanOrEqual(70);
});

it('ConjunctionEvent riskScore is LOW for distant miss', function () {
    $event = ConjunctionEvent::factory()->low()->make();
    expect($event->riskScore())->toBeLessThan(40);
});

it('ConjunctionEvent riskLevel matches riskScore', function () {
    $high = ConjunctionEvent::factory()->high()->make();
    $low = ConjunctionEvent::factory()->low()->make();

    expect($high->riskLevel())->toBe('HIGH')
        ->and($low->riskLevel())->toBe('LOW');
});
