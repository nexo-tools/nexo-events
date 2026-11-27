<?php

// GET /auth/nexo/enter?to=/path — how other Nexo apps (switcher, deep links,
// "Sign in again") send a person into this tool. (§2.5, AC-ENTER)

use App\Models\User;
use App\Services\NexoSso\NexoSsoFlows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/NexoSsoTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'nexo-sso.enabled' => true,
        'nexo-sso.issuer' => 'https://nexoid.test',
        'nexo-sso.client_id' => '11111111-2222-3333-4444-555555555555',
    ]);
});

test('AC-ENTER-1: a guest goes to authorize WITHOUT prompt and the flow remembers `to`', function (): void {
    nexoSsoFakeProvider();

    $response = $this->get(route('nexo-sso.enter', ['to' => '/some/page?tab=2']));

    $response->assertOk();
    expect(nexoSsoAuthorizeUrl($response))->toStartWith('https://nexoid.test/oauth/authorize?');
    $query = nexoSsoAuthorizeQuery($response);
    expect($query)->not->toHaveKey('prompt');

    $flow = nexoSsoStoredFlow($query['state']);
    expect($flow['mode'])->toBe(NexoSsoFlows::INTERACTIVE)
        ->and($flow['return'])->toBe('/some/page?tab=2');
});

test('AC-ENTER-1: after Nexo ID the guest lands on `to`, not on url.intended or the panel', function (): void {
    nexoSsoFakeProvider();

    $this->withSession(nexoSsoFlowSession(NexoSsoFlows::INTERACTIVE, '/some/page') + ['url.intended' => url('/stale')])
        ->get(route('nexo-sso.callback', ['code' => 'auth-code', 'state' => nexoSsoState()]))
        ->assertRedirect(url('/some/page'));

    $this->assertAuthenticated();
    expect(session('url.intended'))->toBeNull();
});

test('AC-ENTER-2: a signed-in SSO session goes straight to `to` (0 clicks, no provider call)', function (): void {
    Http::fake();
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user))
        ->get(route('nexo-sso.enter', ['to' => '/some/page']))
        ->assertRedirect(url('/some/page'));

    Http::assertNothingSent();
});

test('AC-ENTER-2: in transition and sso modes too, a signed-in SSO session goes straight to `to`', function (string $mode): void {
    config(['nexo-sso.auth_mode' => $mode]);
    Http::fake();
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user))
        ->get(route('nexo-sso.enter', ['to' => '/some/page']))
        ->assertRedirect(url('/some/page'));

    Http::assertNothingSent();
})->with(['transition', 'sso']);

test('AC-ENTER-2: without `to` a signed-in session lands on the panel (home)', function (): void {
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user))
        ->get(route('nexo-sso.enter'))
        ->assertRedirect(url(config('nexo-sso.home')));
});

test('AC-ENTER-3: anything but a same-origin path falls back to the panel (anti open-redirect)', function (string $to): void {
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user))
        ->get(route('nexo-sso.enter').'?to='.rawurlencode($to))
        ->assertRedirect(url(config('nexo-sso.home')));
})->with([
    'absolute https' => 'https://evil.test/phish',
    'protocol-relative' => '//evil.test/phish',
    'backslash host' => '/\\evil.test',
    'javascript scheme' => 'javascript:alert(1)',
    'data scheme' => 'data:text/html,hi',
    'relative without slash' => 'evil.test',
    'control character' => "/ok\r\nLocation: https://evil.test",
    'loop into auth/nexo' => '/auth/nexo/enter?to=/x',
    'empty' => '',
]);

test('AC-ENTER-3: an unsafe `to` never reaches the flow either (guest)', function (): void {
    nexoSsoFakeProvider();

    $query = nexoSsoAuthorizeQuery($this->get(route('nexo-sso.enter', ['to' => '//evil.test'])));

    expect(nexoSsoStoredFlow($query['state'])['return'])->toBe(config('nexo-sso.home'));
});

test('AC-ENTER-4: with SSO off the route does not answer', function (): void {
    config(['nexo-sso.enabled' => false]);

    $this->get(route('nexo-sso.enter'))->assertNotFound();
});

test('AC-ENTER-5: with the provider unreachable a guest gets the friendly error, never a 500', function (): void {
    Http::fake(fn () => throw new ConnectionException('provider down'));

    $this->get(route('nexo-sso.enter', ['to' => '/x']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('nexo_sso');
});

test('AC-ENTER-6: in transition and local modes a password session is simply let in — no implicit link through Nexo ID', function (string $mode): void {
    config(['nexo-sso.auth_mode' => $mode, 'nexo-sso.transition_until' => now()->addMonth()->format('Y-m-d')]);
    Http::fake();
    $user = User::factory()->create(['email' => 'user@example.com']);

    $this->actingAs($user)
        ->get(route('nexo-sso.enter', ['to' => '/x']))
        ->assertRedirect(url('/x'));

    Http::assertNothingSent();
    expect($user->fresh()->nexo_id_sub)->toBeNull()
        ->and(session('nexo_sso.flows'))->toBeNull();
})->with(['transition', 'local']);

test('AC-ENTER-6: in sso mode a password session (it has no place there) goes through Nexo ID like a guest', function (): void {
    config(['nexo-sso.auth_mode' => 'sso']);
    nexoSsoFakeProvider();

    $response = $this->actingAs(User::factory()->create())->get(route('nexo-sso.enter', ['to' => '/x']));

    $response->assertOk();
    expect(nexoSsoAuthorizeUrl($response))->toStartWith('https://nexoid.test/oauth/authorize?');
});

// v2.3: a guest's way to Nexo ID is a page of this origin, not a 302. WebKit
// (Safari, every browser on iOS) mishandles a cross-origin redirect inside a
// history traversal: «atrás» after «Cerrar sesión» reaches a panel that `auth`
// sends here, and the 302 stalled it between different sites (with or without
// COOP) or, within one site and with COOP, showed Nexo ID's login under this
// tool's address and CSP, unstyled.

test('AC-ENTER-7: a guest gets a page of this origin that jumps to authorize — no redirect', function (): void {
    nexoSsoFakeProvider();

    $response = $this->get(route('nexo-sso.enter', ['to' => '/some/page']));

    $response->assertOk();
    expect($response->headers->get('Location'))->toBeNull()
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('X-Robots-Tag'))->toContain('noindex');

    $html = (string) $response->getContent();
    $url = nexoSsoAuthorizeUrl($response);
    preg_match('/<a href="([^"]*)">/', $html, $link);

    expect($html)->toContain('data-nexo-sso-handoff')
        ->and($url)->toStartWith('https://nexoid.test/oauth/authorize?')
        ->and($html)->toContain('&amp;state=')
        ->and(nexoSsoStoredFlow(nexoSsoAuthorizeQuery($response)['state'])['return'])->toBe('/some/page')
        // «Continue» goes back through enter (direct=1), never to the page's own authorize URL.
        ->and(html_entity_decode($link[1] ?? '', ENT_QUOTES | ENT_HTML5))->toBe(route('nexo-sso.enter', ['to' => '/some/page', 'direct' => '1']));
});

test('AC-ENTER-7: «Continue» (direct=1) is a plain redirect to authorize with a state of its own — never the page\'s', function (): void {
    nexoSsoFakeProvider();

    $page = nexoSsoAuthorizeQuery($this->get(route('nexo-sso.enter', ['to' => '/some/page'])));
    $direct = $this->get(route('nexo-sso.enter', ['to' => '/some/page', 'direct' => '1']));

    $direct->assertRedirect();
    expect((string) $direct->headers->get('Location'))->toStartWith('https://nexoid.test/oauth/authorize?')
        ->and((string) $direct->getContent())->not->toContain('data-nexo-sso-handoff');
    $query = nexoSsoAuthorizeQuery($direct);
    expect($query['state'])->not->toBe($page['state'])
        ->and($query)->not->toHaveKey('prompt')
        ->and(nexoSsoStoredFlow($query['state'])['return'])->toBe('/some/page')
        // The page's flow is still there: whichever answer comes back first is valid.
        ->and(nexoSsoStoredFlow($page['state']))->not->toBeNull();
});

test('AC-ENTER-7: with signup=1 «Continue» keeps it, and its redirect asks for prompt=create', function (): void {
    nexoSsoFakeProvider();

    preg_match('/<a href="([^"]*)">/', (string) $this->get(route('nexo-sso.enter', ['to' => '/x', 'signup' => '1']))->getContent(), $link);
    $continue = html_entity_decode($link[1] ?? '', ENT_QUOTES | ENT_HTML5);

    expect($continue)->toBe(route('nexo-sso.enter', ['to' => '/x', 'signup' => '1', 'direct' => '1']))
        ->and(nexoSsoAuthorizeQuery($this->get($continue))['prompt'] ?? null)->toBe('create');
});

test('AC-ENTER-7: «Continue» once the session is open goes straight to `to`', function (): void {
    Http::fake();
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user))
        ->get(route('nexo-sso.enter', ['to' => '/some/page', 'direct' => '1']))
        ->assertRedirect(url('/some/page'));

    Http::assertNothingSent();
});

test('AC-ENTER-7: any other value of `direct` still gets the page', function (string $value): void {
    nexoSsoFakeProvider();

    $this->get(route('nexo-sso.enter').'?to=/x&direct='.rawurlencode($value))
        ->assertOk()
        ->assertSee('data-nexo-sso-handoff', false);
})->with(['0', '01', '2', 'true', 'yes', '']); // ' 1 ' would be '1': TrimStrings trims the query too

test('AC-ENTER-7: the page loads nothing — no script for the CSP to allow, no stylesheet for the refresh to wait on', function (): void {
    nexoSsoFakeProvider();

    $html = (string) $this->get(route('nexo-sso.enter', ['to' => '/x']))->getContent();

    expect($html)->not->toContain('<script')
        ->and($html)->not->toMatch('/<link\b/i')
        ->and($html)->not->toMatch('/<img\b/i');
});

test('AC-ENTER-7: the page follows the shared nexo-theme cookie, else the system', function (?string $cookie, string $scheme): void {
    nexoSsoFakeProvider();

    $request = $cookie === null ? $this : $this->withUnencryptedCookie('nexo-theme', $cookie);

    expect((string) $request->get(route('nexo-sso.enter', ['to' => '/x']))->getContent())
        ->toContain('<meta name="color-scheme" content="'.$scheme.'">');
})->with([
    'dark' => ['dark', 'dark'],
    'light' => ['light', 'light'],
    'no cookie' => [null, 'light dark'],
    'anything else' => ['"><script>', 'light dark'],
]);

test('AC-ENTER-7: an absolute http(s) authorize URL goes into the page, as it comes', function (string $endpoint): void {
    nexoSsoFakeProvider(null, ['authorization_endpoint' => $endpoint]);

    $response = $this->get(route('nexo-sso.enter', ['to' => '/x']));

    $response->assertOk()->assertSee('data-nexo-sso-handoff', false);
    expect(nexoSsoAuthorizeUrl($response))->toStartWith($endpoint.'?');
})->with([
    'http (a local stack)' => 'http://nexoid.localhost:9100/oauth/authorize',
    'uppercase scheme' => 'HTTPS://nexoid.test/oauth/authorize',
    'a port' => 'https://nexoid.test:8443/oauth/authorize',
    'an IPv6 host' => 'https://[::1]:8443/oauth/authorize',
]);

test('AC-ENTER-7: only an absolute http(s) authorize URL goes into the page; anything else is the friendly error', function (string $endpoint): void {
    nexoSsoFakeProvider(null, ['authorization_endpoint' => $endpoint]);

    $response = $this->get(route('nexo-sso.enter', ['to' => '/x']));

    $response->assertRedirect(route('login'))->assertSessionHasErrors('nexo_sso');
    expect((string) $response->getContent())->not->toContain('data-nexo-sso-handoff');
})->with([
    'empty (the page would reload itself forever)' => '',
    'relative' => '/oauth/authorize',
    'protocol-relative' => '//evil.test/oauth/authorize',
    'no host' => 'https://',
    'javascript scheme' => 'javascript:alert(1)',
    'data scheme' => 'data:text/html,hi',
]);

test('AC-ENTER-7: «Continue» (direct=1) goes through the same URL guard — never a redirect to anything but an absolute http(s) URL', function (string $endpoint): void {
    nexoSsoFakeProvider(null, ['authorization_endpoint' => $endpoint]);

    $this->get(route('nexo-sso.enter', ['to' => '/x', 'direct' => '1']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('nexo_sso');
})->with([
    'empty' => '',
    'relative' => '/oauth/authorize',
    'javascript scheme' => 'javascript:alert(1)',
]);

test('AC-ENTER-7: «Continue» (direct=1) with the provider unreachable gets the friendly error, never a 500', function (): void {
    Http::fake(fn () => throw new ConnectionException('provider down'));

    $this->get(route('nexo-sso.enter', ['to' => '/x', 'direct' => '1']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('nexo_sso');
});

test('AC-ENTER-7: the «Continue» link shows only after 3 s — normally the page is gone by then', function (): void {
    nexoSsoFakeProvider();

    $html = (string) $this->get(route('nexo-sso.enter', ['to' => '/x']))->getContent();

    expect($html)->toContain('animation: nexo-reveal 0s 3s both;')
        ->and($html)->toContain('@keyframes nexo-reveal { from { visibility: hidden; } }');
});

test('AC-ENTER-7: a signed-in session is still a plain redirect to `to`, with no page in between', function (): void {
    $user = User::factory()->create();

    $response = $this->withSession(nexoSsoSignedIn($this, $user))->get(route('nexo-sso.enter', ['to' => '/some/page']));

    $response->assertRedirect(url('/some/page'));
    expect((string) $response->getContent())->not->toContain('data-nexo-sso-handoff');
});
