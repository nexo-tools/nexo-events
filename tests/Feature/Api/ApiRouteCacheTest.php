<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/**
 * AC-API-1, the half of it that a normal request can never reach: the API has
 * to survive `route:cache`.
 *
 * This is the whole reason the env gate lives in the middleware instead of
 * around the route registration, and it is not a hypothetical — this project
 * has already lost debugging time to it once. `routes/nexo-sso.php` returns
 * early when SSO is off, so `route:cache` baked the ABSENCE of those routes
 * into the cache, and flipping NEXO_SSO_ENABLED in a production .env did
 * nothing at all until somebody re-cached (DEPLOYMENT.md). If the API had been
 * written the same way, the day the flag is turned on would be a day of
 * confused 404s at the worst possible moment.
 *
 * `route:cache` boots a fresh application to collect routes, so the routes here
 * are compiled with the flag in its default state: OFF. That is exactly the
 * scenario that matters — a deploy that caches routes before the API is ever
 * enabled must still leave them there.
 */
afterEach(function (): void {
    // A cached route file left behind would be a stale, invisible copy of the
    // routes for every later test and for the local dev app.
    Artisan::call('route:clear');
});

test('AC-API-1: route:cache with the flag off still bakes the API routes in', function (): void {
    config(['nexo.api.enabled' => false]);

    Artisan::call('route:cache');

    $cached = collect(File::glob(base_path('bootstrap/cache/routes-*.php')))->first();

    expect($cached)->not->toBeNull('route:cache produced no cached route file.');

    $contents = (string) File::get((string) $cached);

    expect($contents)->toContain('api/v1/me')
        ->and($contents)->toContain('api/v1/events')
        ->and($contents)->toContain('app/integraciones');
});

test('AC-API-1: an app booted from the cached routes still answers 404 off and reaches auth on', function (): void {
    Artisan::call('route:cache');

    // Boot the application again: from here on the router is the compiled file,
    // not routes/api.php.
    $this->refreshApplication();
    $this->withoutVite();

    config(['nexo.api.enabled' => false]);
    $this->getJson('/api/v1/me')->assertNotFound();

    config(['nexo.api.enabled' => true]);
    // 401 and not 404 is the whole point: the route exists in the cached file
    // and the request reached the token check. (A valid token would need the
    // database, and refreshing the application dropped the in-memory one.)
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
