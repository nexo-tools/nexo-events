<?php

use App\Models\Event;

/**
 * Theme and language are meant to follow a person ACROSS the Nexo tools, which
 * only works if the cookies carrying them are readable by the sibling apps.
 * Laravel encrypts cookies with this app's key — which no sibling has — so an
 * encrypted value would be silently unreadable everywhere else, and the inline
 * theme-init script could not read it either.
 */
it('AC-CHROME-1: persists the chosen language in the shared nexo-lang cookie, unencrypted', function (): void {
    $response = $this->get(route('home').'?lang=pt');

    $response->assertOk();
    // NB: firstWhere('name', …) does not work on Symfony Cookie objects — the
    // name is private with no matching property, so it silently finds nothing.
    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c): bool => $c->getName() === 'nexo-lang');

    expect($cookie)->not->toBeNull()
        // Readable as-is: an encrypted payload would not equal the locale.
        ->and($cookie->getValue())->toBe('pt')
        ->and($cookie->isHttpOnly())->toBeFalse(); // the theme/locale UI reads it client-side
});

it('AC-CHROME-1: honours a language cookie set by another tool', function (): void {
    // withUnencryptedCookie, not withCookie: the test helper encrypts by
    // default, and this cookie is deliberately exempt from encryption — an
    // encrypted value is exactly what a sibling tool could NOT produce.
    $this->withUnencryptedCookie('nexo-lang', 'pt')
        ->get(route('home'))
        ->assertOk();

    expect(app()->getLocale())->toBe('pt');
});

it('AC-CHROME-1: an explicit ?lang= wins over the inherited cookie', function (): void {
    $this->withUnencryptedCookie('nexo-lang', 'pt')
        ->get(route('home').'?lang=en')
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('AC-CHROME-2: the theme-init script stays allow-listed by hash, never by unsafe-inline', function (): void {
    $html = (string) $this->get(route('home'))->getContent();
    $csp = (string) $this->get(route('home'))->headers->get('Content-Security-Policy');

    preg_match('/<script>(.*?)<\/script>/s', $html, $m);
    expect($m[1] ?? null)->not->toBeNull();

    // The hash in the CSP must be the hash of what is actually served: drift
    // here means the theme flashes or the script is blocked outright in prod.
    $hash = 'sha256-'.base64_encode(hash('sha256', $m[1], true));

    expect($csp)->toContain($hash)
        ->and($csp)->not->toContain("script-src 'self' 'unsafe-inline'");
});

it('AC-CHROME-3: the beacon stays silent unless this instance opts in', function (): void {
    $event = Event::factory()->create();

    $html = (string) $this->get(route('public.event', $event))->getContent();
    expect($html)->not->toContain('nexo:beacon-endpoint');

    config(['nexo.beacon.enabled' => true]);
    $optedIn = (string) $this->get(route('public.event', $event))->getContent();
    expect($optedIn)->toContain('nexo:beacon-endpoint');
});
