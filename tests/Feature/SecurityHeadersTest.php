<?php

use App\Models\Event;

it('sends security headers on public pages', function () {
    $event = Event::factory()->create();

    $response = $this->get(route('public.event', $event));

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    expect($response->headers->get('Permissions-Policy'))->toContain('camera=(self)');
});

it('AC-SCAN-8: grants camera to same-origin only, and still denies microphone and geolocation', function () {
    $policy = (string) $this->get('/')->headers->get('Permissions-Policy');

    // The door scanner needs the organizer's own camera (SPEC-scanner) — but
    // only ours, never delegated to an embedded frame, and nothing else opens up.
    expect($policy)->toContain('camera=(self)')
        ->and($policy)->toContain('microphone=()')
        ->and($policy)->toContain('geolocation=()')
        ->and($policy)->not->toContain('camera=*')
        ->and($policy)->not->toContain('microphone=(self)')
        ->and($policy)->not->toContain('geolocation=(self)');
});

it('AC-SCAN-9: adding the scanner opened no new script capability in the CSP', function () {
    $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

    // jsQR is pure JS precisely so none of these are needed. A wasm decoder
    // would have forced wasm-unsafe-eval, a worker-based one worker-src.
    expect($csp)->not->toContain('wasm-unsafe-eval')
        ->and($csp)->not->toContain('worker-src')
        ->and($csp)->not->toContain("script-src 'self' 'unsafe-inline'")
        ->and($csp)->toContain("connect-src 'self'"); // the scanner posts same-origin
});

it('sends a self-contained content-security-policy', function () {
    $response = $this->get('/');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'");

    // The only permitted external host is the Nexo Tools hub (opt-in cookieless
    // beacon in connect-src). Without it the emitter renders and the browser
    // drops every sendBeacon silently — this tool's visits simply never arrived.
    // No other external host leaks into the policy.
    expect($csp)->toContain("connect-src 'self' https://nexotools.alvarocdev.com");
    expect(str_replace('https://nexotools.alvarocdev.com', '', $csp))
        ->not->toContain('http://')
        ->not->toContain('https://');
});

it('does not advertise HSTS over plain http', function () {
    $response = $this->get('/');

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

it('keeps the .htaccess CSP in sync with the middleware CSP', function () {
    // On LiteSpeed the web server strips the PHP-sent CSP (Force-HTTPS), so it is
    // re-asserted in public/.htaccess. The two must match or prod silently weakens.
    $middlewareCsp = $this->get('/')->headers->get('Content-Security-Policy');

    $htaccess = file_get_contents(public_path('.htaccess'));
    preg_match('/Header always set Content-Security-Policy "([^"]*)"/', $htaccess, $m);

    expect($m[1] ?? null)->not->toBeNull()
        ->and($m[1])->toBe($middlewareCsp);
});
