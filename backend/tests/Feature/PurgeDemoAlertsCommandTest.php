<?php

use App\Models\ConjunctionAlert;
use App\Models\ConjunctionEvent;

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Create a demo conjunction_event (DEMO-* cdm_id). */
function demoEvent(string $suffix = '0001'): ConjunctionEvent
{
    return ConjunctionEvent::factory()->create([
        'cdm_id' => "DEMO-{$suffix}",
        'source' => 'demo',
    ]);
}

/** Create a real CDM conjunction_event. */
function realEvent(string $suffix = '9001'): ConjunctionEvent
{
    return ConjunctionEvent::factory()->create([
        'cdm_id' => "CDM-{$suffix}",
        'source' => 'space_track_cdm',
    ]);
}

/** Create a demo conjunction_alert. */
function demoAlert(array $extra = []): ConjunctionAlert
{
    return ConjunctionAlert::factory()->create(array_merge(['source' => 'demo'], $extra));
}

/** Create a real CDM conjunction_alert. */
function cdmAlert(array $extra = []): ConjunctionAlert
{
    return ConjunctionAlert::factory()->create(array_merge(['source' => 'space_track_cdm'], $extra));
}

/** Create a real SGP4 conjunction_alert. */
function sgp4Alert(array $extra = []): ConjunctionAlert
{
    return ConjunctionAlert::factory()->create(array_merge(['source' => 'sgp4'], $extra));
}

// ── Nothing to purge ─────────────────────────────────────────────────────────

it('reports nothing to purge when DB is clean', function () {
    $this->artisan('alerts:purge-demo')
         ->expectsOutputToContain('Nothing to purge')
         ->assertSuccessful();
});

// ── Demo alert deletion ───────────────────────────────────────────────────────

it('deletes alerts with source=demo', function () {
    demoAlert();
    demoAlert();

    $this->artisan('alerts:purge-demo')->assertSuccessful();

    expect(ConjunctionAlert::count())->toBe(0);
});

it('deletes null-source alerts with no conjunction_event_id (old AlertDemoSeeder rows)', function () {
    ConjunctionAlert::factory()->create(['source' => null, 'conjunction_event_id' => null]);

    $this->artisan('alerts:purge-demo')->assertSuccessful();

    expect(ConjunctionAlert::count())->toBe(0);
});

it('deletes alerts linked to DEMO-* conjunction events regardless of source label', function () {
    $event = demoEvent();
    ConjunctionAlert::factory()->create([
        'source'               => 'space_track_cdm', // old seeder assigned wrong source
        'conjunction_event_id' => $event->id,
    ]);

    $this->artisan('alerts:purge-demo')->assertSuccessful();

    expect(ConjunctionAlert::count())->toBe(0);
});

// ── Demo event deletion ───────────────────────────────────────────────────────

it('deletes conjunction_events with DEMO-* cdm_id', function () {
    demoEvent('0001');
    demoEvent('0002');

    $this->artisan('alerts:purge-demo')->assertSuccessful();

    expect(ConjunctionEvent::count())->toBe(0);
});

// ── Real data preservation ───────────────────────────────────────────────────

it('preserves real CDM alerts', function () {
    demoAlert();
    cdmAlert();

    $this->artisan('alerts:purge-demo')->assertSuccessful();

    expect(ConjunctionAlert::count())->toBe(1)
        ->and(ConjunctionAlert::first()->source)->toBe('space_track_cdm');
});

it('preserves real SGP4 alerts', function () {
    demoAlert();
    sgp4Alert();

    $this->artisan('alerts:purge-demo')->assertSuccessful();

    expect(ConjunctionAlert::count())->toBe(1)
        ->and(ConjunctionAlert::first()->source)->toBe('sgp4');
});

it('preserves real CDM conjunction_events', function () {
    demoEvent();
    realEvent();

    $this->artisan('alerts:purge-demo')->assertSuccessful();

    expect(ConjunctionEvent::count())->toBe(1)
        ->and(ConjunctionEvent::first()->source)->toBe('space_track_cdm');
});

it('preserves alerts linked to real CDM events', function () {
    $event = realEvent();
    cdmAlert(['conjunction_event_id' => $event->id]);
    demoAlert();

    $this->artisan('alerts:purge-demo')->assertSuccessful();

    expect(ConjunctionAlert::count())->toBe(1)
        ->and(ConjunctionAlert::first()->source)->toBe('space_track_cdm');
});

// ── Dry run ───────────────────────────────────────────────────────────────────

it('dry-run does not delete any rows', function () {
    demoAlert();
    demoEvent();

    $this->artisan('alerts:purge-demo --dry-run')
         ->expectsOutputToContain('dry-run')
         ->assertSuccessful();

    expect(ConjunctionAlert::count())->toBe(1);
    expect(ConjunctionEvent::count())->toBe(1);
});

it('dry-run reports the count of rows that would be deleted', function () {
    demoAlert();
    demoAlert();
    demoEvent();

    $this->artisan('alerts:purge-demo --dry-run')
         ->expectsOutputToContain('Found 2 demo alert(s) and 1 demo event(s)')
         ->assertSuccessful();
});
