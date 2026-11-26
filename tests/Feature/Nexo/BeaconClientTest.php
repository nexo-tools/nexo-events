<?php

// Guardian (nexo-ui v2.1, 2026-11-26): the cookieless pageview beacon reaches
// the hub from every app without a single console error (STANDARD «Beacon»).
//
// Why it exists: in production every page of every tool logged a CORS error
// and no visit from another app was counted (H-nexoid-15, 470 errors in one
// Chromium run). The emitter used navigator.sendBeacon() with a JSON Blob:
// that is a preflighted request, and sendBeacon always sends credentials,
// which the hub does not allow. And three apps pinned the production hub in
// connect-src, so a local or self-hosted hub was blocked by the CSP.
//
// What it pins, in this app:
//   - resources/js/nexo-beacon.js is the template's emitter (fetch with
//     credentials 'omit', keepalive, a text/plain body: no preflight, no
//     cookie) and app.js imports it;
//   - partials/beacon.blade.php renders its metas only with the beacon on, with
//     this app's registry key as the origin;
//   - the CSP's connect-src allows the origin of the configured endpoint
//     (never a fixed host).
// The hub side (CORS from the registry, no credentials, the preflight and the
// throttle) is the hub's own test (nexotools: BeaconIngestTest).
// Copy into tests/Feature/Nexo/ unchanged.

it('ships the template emitter: fetch without credentials, keepalive, no preflight', function () {
    $source = (string) file_get_contents(resource_path('js/nexo-beacon.js'));

    expect($source)
        ->toContain('doNotTrack')
        ->toContain('globalPrivacyControl')
        ->toContain("credentials: 'omit'")
        ->toContain('keepalive: true')
        ->toContain("'Content-Type': 'text/plain;charset=UTF-8'")
        ->toContain("event: 'pageview'")
        ->not->toContain('sendBeacon(')
        ->not->toContain('application/json');

    expect((string) file_get_contents(resource_path('js/app.js')))->toContain('nexo-beacon.js');
});

it('renders the beacon metas only when the beacon is on, with this app as the origin', function () {
    config(['nexo.beacon.enabled' => false]);
    expect(view('partials.beacon')->render())->not->toContain('nexo:beacon');

    config([
        'nexo.beacon.enabled' => true,
        'nexo.beacon.endpoint' => 'https://hub.example.test/beacon',
        'nexo.beacon.origin' => null,
    ]);
    $html = view('partials.beacon')->render();

    expect($html)
        ->toContain('<meta name="nexo:beacon-endpoint" content="https://hub.example.test/beacon">')
        ->toContain('<meta name="nexo:beacon-origin" content="'.config('nexo-ecosystem.current').'">');
});

it('puts the beacon metas on the app chrome when the beacon is on', function () {
    config(['nexo.beacon.enabled' => true, 'nexo.beacon.endpoint' => 'https://hub.example.test/beacon']);

    $this->get(route('help'))->assertOk()->assertSee('name="nexo:beacon-endpoint"', false);
});

it('allows the configured hub in connect-src, not a fixed host', function () {
    config(['nexo.beacon.enabled' => true, 'nexo.beacon.endpoint' => 'http://hub.example.test:8270/beacon']);

    $csp = (string) $this->get(route('help'))->headers->get('Content-Security-Policy');
    preg_match('/(?:^|;)\s*connect-src ([^;]*)/', $csp, $match);

    expect($match[1] ?? '')->toContain('http://hub.example.test:8270');
});
