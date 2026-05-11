<?php

use App\Models\ConjunctionAlert;
use App\Models\ConjunctionEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WatchedSatellite;
use Database\Seeders\DatabaseSeeder;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Create an authenticated user, optionally on a paid plan.
 * No subscription row = free plan (currentPlan() returns 'free').
 *
 * @return array{0: User, 1: string}
 */
function alertUser(string $plan = 'starter'): array
{
    $user = User::factory()->create();

    if ($plan !== 'free') {
        Subscription::factory()->create(['user_id' => $user->id, 'plan' => $plan]);
    }

    return [$user, $user->createToken('test')->plainTextToken];
}

/** Attach a watched satellite to $user and return the model. */
function watchedSat(User $user, string $noradId = '25544', string $name = 'ISS (ZARYA)'): WatchedSatellite
{
    return WatchedSatellite::factory()->forNorad($noradId, $name)->create(['user_id' => $user->id]);
}

/** Create an upcoming conjunction alert for the given primary NORAD ID. */
function upcomingAlert(string $noradId, array $overrides = []): ConjunctionAlert
{
    return ConjunctionAlert::factory()->forPrimary($noradId)->create($overrides);
}

// ── Auth guards ───────────────────────────────────────────────────────────────

it('returns 401 for unauthenticated requests', function () {
    $this->getJson('/api/alerts')->assertUnauthorized();
});

it('returns 403 for free-plan users', function () {
    [, $token] = alertUser('free');

    $this->withToken($token)
         ->getJson('/api/alerts')
         ->assertForbidden()
         ->assertJsonPath('error.code', 'ALERTS_NOT_AVAILABLE');
});

it('allows starter-plan users to access alerts', function () {
    [, $token] = alertUser('starter');

    $this->withToken($token)->getJson('/api/alerts')->assertOk();
});

it('allows pro-plan users to access alerts', function () {
    [, $token] = alertUser('pro');

    $this->withToken($token)->getJson('/api/alerts')->assertOk();
});

// ── Empty states ──────────────────────────────────────────────────────────────

it('returns empty data when user has no watched satellites', function () {
    [, $token] = alertUser();

    $this->withToken($token)
         ->getJson('/api/alerts')
         ->assertOk()
         ->assertJsonPath('data', []);
});

it('returns empty data when watched sats have no alerts', function () {
    [$user, $token] = alertUser();
    watchedSat($user, '25544');

    $this->withToken($token)
         ->getJson('/api/alerts')
         ->assertOk()
         ->assertJsonPath('data', []);
});

// ── Alert retrieval ───────────────────────────────────────────────────────────

it('returns upcoming alerts for user watched satellites', function () {
    [$user, $token] = alertUser();
    watchedSat($user, '25544');
    upcomingAlert('25544');

    $res = $this->withToken($token)->getJson('/api/alerts')->assertOk();

    expect($res->json('data'))->toHaveCount(1);
});

it('response includes all required fields', function () {
    [$user, $token] = alertUser();
    watchedSat($user, '25544');
    upcomingAlert('25544');

    $alert = $this->withToken($token)->getJson('/api/alerts')->json('data.0');

    expect($alert)->toHaveKeys([
        'id',
        'primary_norad_id',
        'primary_name',
        'secondary_norad_id',
        'secondary_name',
        'tca',
        'hours_until_tca',
        'miss_distance_km',
        'probability',
        'risk_score',
        'risk_level',
        'source',
    ]);
});

it('risk_level is derived correctly from risk_score', function () {
    [$user, $token] = alertUser();
    watchedSat($user, '25544');
    upcomingAlert('25544', ['risk_score' => 80]);
    upcomingAlert('25544', ['risk_score' => 50]);
    upcomingAlert('25544', ['risk_score' => 20]);

    $data = $this->withToken($token)->getJson('/api/alerts')->json('data');
    $levels = collect($data)->pluck('risk_level', 'risk_score');

    expect($levels[80])->toBe('HIGH')
        ->and($levels[50])->toBe('MEDIUM')
        ->and($levels[20])->toBe('LOW');
});

it('returns alerts sorted by TCA ascending', function () {
    [$user, $token] = alertUser();
    watchedSat($user, '25544');

    upcomingAlert('25544', ['tca' => now()->addDays(4)]);
    upcomingAlert('25544', ['tca' => now()->addHours(2)]);
    upcomingAlert('25544', ['tca' => now()->addDays(2)]);

    $data = $this->withToken($token)->getJson('/api/alerts')->json('data');

    expect($data[0]['hours_until_tca'])->toBeLessThan($data[1]['hours_until_tca'])
        ->and($data[1]['hours_until_tca'])->toBeLessThan($data[2]['hours_until_tca']);
});

// ── Scoping ───────────────────────────────────────────────────────────────────

it('does not return alerts for unmonitored satellites', function () {
    [$user, $token] = alertUser();
    watchedSat($user, '25544');

    // Alert for a satellite this user is NOT watching
    upcomingAlert('99999');

    $data = $this->withToken($token)->getJson('/api/alerts')->json('data');

    expect($data)->toBeEmpty();
});

it('does not return alerts with a past TCA', function () {
    [$user, $token] = alertUser();
    watchedSat($user, '25544');
    ConjunctionAlert::factory()->forPrimary('25544')->past()->create();

    $data = $this->withToken($token)->getJson('/api/alerts')->json('data');

    expect($data)->toBeEmpty();
});

it('does not return alerts whose TCA is beyond the 5-day horizon', function () {
    [$user, $token] = alertUser();
    watchedSat($user, '25544');
    ConjunctionAlert::factory()->forPrimary('25544')->distant()->create();

    $data = $this->withToken($token)->getJson('/api/alerts')->json('data');

    expect($data)->toBeEmpty();
});

it('scopes alerts between users — each user only sees their own', function () {
    // Use actingAs() to bypass Sanctum token resolution and test business logic directly
    [$user1] = alertUser();
    [$user2] = alertUser();

    WatchedSatellite::create(['user_id' => $user1->id, 'norad_id' => '25544', 'name' => 'ISS']);
    WatchedSatellite::create(['user_id' => $user2->id, 'norad_id' => '43013', 'name' => 'GOES-16']);

    ConjunctionAlert::create([
        'primary_norad_id'   => '25544', 'primary_name'       => 'ISS',
        'secondary_norad_id' => '11111', 'secondary_name'     => 'DEB-A',
        'tca'                => now()->addDay(), 'miss_distance_km' => 1.5, 'risk_score' => 50,
    ]);
    ConjunctionAlert::create([
        'primary_norad_id'   => '43013', 'primary_name'       => 'GOES-16',
        'secondary_norad_id' => '22222', 'secondary_name'     => 'DEB-B',
        'tca'                => now()->addDays(2), 'miss_distance_km' => 2.5, 'risk_score' => 30,
    ]);

    $data1 = $this->actingAs($user1)->getJson('/api/alerts')->json('data');
    $data2 = $this->actingAs($user2)->getJson('/api/alerts')->json('data');

    expect($data1)->toHaveCount(1)
        ->and($data1[0]['primary_norad_id'])->toBe('25544')
        ->and($data2)->toHaveCount(1)
        ->and($data2[0]['primary_norad_id'])->toBe('43013');
});

it('returns alerts for all of the users watched satellites', function () {
    [$user, $token] = alertUser();
    watchedSat($user, '25544');
    watchedSat($user, '20580');

    upcomingAlert('25544');
    upcomingAlert('20580');

    $data = $this->withToken($token)->getJson('/api/alerts')->json('data');

    expect($data)->toHaveCount(2);

    $noradIds = collect($data)->pluck('primary_norad_id')->sort()->values()->all();
    expect($noradIds)->toBe(['20580', '25544']);
});

// ── Meta / data credibility ───────────────────────────────────────────────────

it('response includes meta with source when alerts exist', function () {
    [$user, $token] = alertUser();
    watchedSat($user, '25544');
    upcomingAlert('25544', ['source' => 'space_track_cdm']);

    $res = $this->withToken($token)->getJson('/api/alerts')->assertOk();

    expect($res->json('meta'))->toHaveKeys(['source', 'last_updated', 'coverage'])
        ->and($res->json('meta.source'))->toBe('space_track_cdm');
});

it('meta source is sgp4 when all alerts are sgp4', function () {
    [$user, $token] = alertUser();
    watchedSat($user, '25544');
    upcomingAlert('25544', ['source' => 'sgp4']);

    expect($this->withToken($token)->getJson('/api/alerts')->json('meta.source'))->toBe('sgp4');
});

it('meta source is space_track_cdm when any alert is CDM', function () {
    [$user, $token] = alertUser();
    watchedSat($user, '25544');
    upcomingAlert('25544', ['source' => 'sgp4']);
    upcomingAlert('25544', ['source' => 'space_track_cdm']);

    expect($this->withToken($token)->getJson('/api/alerts')->json('meta.source'))->toBe('space_track_cdm');
});

// ── source_configured metadata ────────────────────────────────────────────────

it('response always includes source_configured in meta', function () {
    [, $token] = alertUser();

    $res = $this->withToken($token)->getJson('/api/alerts')->assertOk();

    expect($res->json('meta'))->toHaveKey('source_configured')
        ->and($res->json('meta.source_configured'))->toBeBool();
});

it('meta source_configured is false when Space-Track credentials are absent', function () {
    config(['services.space_track.user' => null, 'services.space_track.pass' => null]);

    [, $token] = alertUser();

    expect($this->withToken($token)->getJson('/api/alerts')->json('meta.source_configured'))->toBeFalse();
});

it('meta source is null when user has no alerts', function () {
    [$user, $token] = alertUser();
    watchedSat($user, '25544');

    expect($this->withToken($token)->getJson('/api/alerts')->json('meta.source'))->toBeNull();
});

// ── DatabaseSeeder does not create demo data ──────────────────────────────────

it('DatabaseSeeder does not create any conjunction alerts', function () {
    $this->seed(DatabaseSeeder::class);

    expect(ConjunctionAlert::count())->toBe(0);
});

it('DatabaseSeeder does not create any conjunction events', function () {
    $this->seed(DatabaseSeeder::class);

    expect(ConjunctionEvent::count())->toBe(0);
});

// ── Factory state tests ───────────────────────────────────────────────────────

it('ConjunctionAlert high() state produces high risk score', function () {
    $alert = ConjunctionAlert::factory()->high()->make();

    expect($alert->risk_score)->toBeGreaterThanOrEqual(75)
        ->and($alert->miss_distance_km)->toBeLessThan(1.0);
});

it('ConjunctionAlert medium() state produces medium risk score', function () {
    $alert = ConjunctionAlert::factory()->medium()->make();

    expect($alert->risk_score)->toBeGreaterThanOrEqual(40)
        ->and($alert->risk_score)->toBeLessThanOrEqual(69);
});

it('ConjunctionAlert low() state produces low risk score', function () {
    $alert = ConjunctionAlert::factory()->low()->make();

    expect($alert->risk_score)->toBeLessThan(40)
        ->and($alert->miss_distance_km)->toBeGreaterThan(3.0);
});

it('WatchedSatellite factory creates with correct user association', function () {
    $user = User::factory()->create();
    $sat  = WatchedSatellite::factory()->iss()->create(['user_id' => $user->id]);

    expect($sat->user_id)->toBe($user->id)
        ->and($sat->norad_id)->toBe('25544')
        ->and($sat->name)->toBe('ISS (ZARYA)');
});
