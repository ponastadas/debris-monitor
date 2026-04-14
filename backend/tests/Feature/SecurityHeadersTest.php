<?php

// Security headers are attached globally by SecurityHeaders middleware.
// We verify them on both the health endpoint (public, no auth) and an
// authenticated API endpoint to confirm the middleware runs everywhere.

it('health endpoint includes X-Frame-Options: DENY', function () {
    $this->getJson('/api/health')
         ->assertHeader('X-Frame-Options', 'DENY');
});

it('health endpoint includes X-Content-Type-Options: nosniff', function () {
    $this->getJson('/api/health')
         ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('health endpoint includes Referrer-Policy: strict-origin-when-cross-origin', function () {
    $this->getJson('/api/health')
         ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('error responses also include security headers', function () {
    // A 401 from an unauthenticated admin request should still carry the headers
    $this->getJson('/api/admin/auth/me')
         ->assertUnauthorized()
         ->assertHeader('X-Frame-Options', 'DENY')
         ->assertHeader('X-Content-Type-Options', 'nosniff');
});
