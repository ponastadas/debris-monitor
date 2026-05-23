<?php

require __DIR__.'/../vendor/autoload.php';

// Inject a per-run APP_KEY before the application boots so that model casts
// using `encrypted` (e.g. AdminAccount.mfa_secret) never throw
// MissingAppKeyException during tests.
//
// This runs before Laravel loads .env.testing, so the immutable Dotenv loader
// will see APP_KEY already set and leave it alone.
//
// No hardcoded key is committed anywhere — each test run gets a fresh random key.
if (empty(getenv('APP_KEY'))) {
    $key = 'base64:'.base64_encode(random_bytes(32));
    putenv("APP_KEY={$key}");
    $_ENV['APP_KEY'] = $key;
    $_SERVER['APP_KEY'] = $key;
}
