<?php

use App\Models\ApiKey;
use App\Models\User;

it('requires authentication to list keys', function () {
    $this->getJson('/api/keys')->assertUnauthorized();
});

it('lists authenticated user keys', function () {
    $user = User::factory()->create();
    ApiKey::factory()->create(['user_id' => $user->id, 'name' => 'My Key']);

    $this->actingAs($user)
        ->getJson('/api/keys')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'My Key');
});

it('creates an api key', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/keys', ['name' => 'Production Key'])
        ->assertCreated()
        ->assertJsonStructure(['id', 'name', 'key', 'tier', 'daily_limit'])
        ->assertJsonPath('tier', 'free')
        ->assertJsonPath('daily_limit', 100);
});

it('rejects key creation without a name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/keys', [])
        ->assertUnprocessable();
});

it('revokes an api key', function () {
    $user = User::factory()->create();
    $key = ApiKey::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson("/api/keys/{$key->id}")
        ->assertOk()
        ->assertJson(['message' => 'API key revoked']);

    expect(ApiKey::withoutTrashed()->find($key->id))->toBeNull();
});

it('cannot revoke another users key', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $key = ApiKey::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other)
        ->deleteJson("/api/keys/{$key->id}")
        ->assertNotFound();
});
