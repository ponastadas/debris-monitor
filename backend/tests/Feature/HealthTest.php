<?php

it('returns healthy status', function () {
    $response = $this->getJson('/api/health');

    $response->assertOk()
             ->assertJsonStructure(['status', 'env', 'time'])
             ->assertJson(['status' => 'ok']);
});
