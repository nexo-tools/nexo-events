<?php

// RP-initiated (central) logout: the tool ends its own session AND hands the
// browser to the provider's end_session_endpoint so the Nexo ID session ends
// too. The provider validates post_logout_redirect_uri against the id_token_hint
// client's registered URIs (see the Nexo ID end_session controller) — so the
// client just needs to send a well-formed request and degrade safely.

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/NexoSsoTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'nexo-sso.enabled' => true,
        'nexo-sso.issuer' => 'https://nexoid.test',
        'nexo-sso.client_id' => '11111111-2222-3333-4444-555555555555',
        'nexo-sso.post_logout_redirect_uri' => 'https://client.test/after-logout',
    ]);
});

test('AC-LOGOUT-1: a successful login stores the raw id_token for the logout hint', function (): void {
    $token = nexoSsoIdToken();
    nexoSsoFakeProvider(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => $token]);

    nexoSsoCallback($this);

    expect(session('nexo_sso.id_token'))->toBe($token);
});

test('AC-LOGOUT-1: logout ends the tool session and redirects to end_session with hint + post_logout_redirect_uri', function (): void {
    nexoSsoFakeProvider();
    nexoSsoCallback($this);
    $this->assertAuthenticated();

    $response = $this->post(route('nexo-sso.logout'));

    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');
    expect($location)->toStartWith('https://nexoid.test/oauth/logout?');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query)->toHaveKey('id_token_hint')
        ->and($query['id_token_hint'])->not->toBe('')
        ->and($query['post_logout_redirect_uri'])->toBe('https://client.test/after-logout');

    $this->assertGuest(); // the local session is gone regardless of the provider round-trip
    expect(session('nexo_sso.id_token'))->toBeNull();
});

test('AC-LOGOUT-2: by default Nexo ID returns the browser to <APP_URL>/auth/nexo/signed-out', function (): void {
    config(['nexo-sso.post_logout_redirect_uri' => '', 'app.url' => 'https://tool.test']);
    nexoSsoFakeProvider();

    $response = $this->withSession(nexoSsoSignedIn($this, User::factory()->create()))->post(route('nexo-sso.logout'));

    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['post_logout_redirect_uri'])->toBe('https://tool.test/auth/nexo/signed-out')
        ->and($query['id_token_hint'])->toBe('previous-id-token')
        ->and($query)->not->toHaveKey('client_id');
});

test('AC-LOGOUT-3: a password session (no Nexo ID session of its own) only ends here', function (): void {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('nexo-sso.logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    Http::assertNothingSent();
});

test('AC-LOGOUT-1: with no end_session_endpoint advertised, logout stays local (no open redirect)', function (): void {
    nexoSsoFakeProvider(null, ['end_session_endpoint' => null]);
    nexoSsoCallback($this);
    $this->assertAuthenticated();

    $this->post(route('nexo-sso.logout'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('AC-LOGOUT-1: with the provider unreachable, the local logout still happens', function (): void {
    Http::fake(fn () => throw new ConnectionException('provider down'));

    $this->withSession(nexoSsoSignedIn($this, User::factory()->create()))
        ->post(route('nexo-sso.logout'))
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

// ---- «Cerrar sesión» from a page whose session is already gone (§0.1 item 2) ----

/** The query of a redirect to Nexo ID's end_session, asserting that's where it goes. */
function nexoSsoEndSessionQuery(TestResponse $response): array
{
    $location = (string) $response->headers->get('Location');
    expect($location)->toStartWith('https://nexoid.test/oauth/logout?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

test('AC-LOGOUT-4: a 419 on «Cerrar sesión» (session expired) goes to end_session without a hint, with client_id', function (string $route): void {
    nexoSsoFakeProvider();
    nexoSsoEnforceCsrf($this);

    $response = $this->post(route($route)); // no _token: the session it was rendered with is gone

    $query = nexoSsoEndSessionQuery($response);
    expect($query)->not->toHaveKey('id_token_hint')
        ->and($query['client_id'])->toBe('11111111-2222-3333-4444-555555555555')
        ->and($query['post_logout_redirect_uri'])->toBe('https://client.test/after-logout');
})->with(['central logout' => 'nexo-sso.logout', 'local logout' => 'logout']);

test('AC-LOGOUT-4: by default that hint-less end_session returns to the signed-out screen', function (): void {
    config(['nexo-sso.post_logout_redirect_uri' => '', 'app.url' => 'https://tool.test']);
    nexoSsoFakeProvider();
    nexoSsoEnforceCsrf($this);

    $query = nexoSsoEndSessionQuery($this->post(route('nexo-sso.logout')));

    expect($query['post_logout_redirect_uri'])->toBe('https://tool.test/auth/nexo/signed-out');
});

test('AC-LOGOUT-4: a forged cross-site logout form signs nobody out here — Nexo ID asks before ending anything', function (): void {
    nexoSsoFakeProvider();
    nexoSsoEnforceCsrf($this);
    $user = User::factory()->create();

    $response = $this->withSession(nexoSsoSignedIn($this, $user))
        ->withHeaders(['Sec-Fetch-Site' => 'cross-site', 'Origin' => 'https://evil.test'])
        ->post(route('nexo-sso.logout'));

    // Never the hint: with it Nexo ID would end the session without asking.
    expect(nexoSsoEndSessionQuery($response))->not->toHaveKey('id_token_hint');
    $this->assertAuthenticatedAs($user);
});

test('AC-LOGOUT-4: a stale token from this same origin (another tab rotated it) also ends the session here', function (): void {
    nexoSsoFakeProvider();
    nexoSsoEnforceCsrf($this);

    $response = $this->withSession(nexoSsoSignedIn($this, User::factory()->create()))
        ->withHeaders(['Origin' => 'http://localhost'])
        ->post(route('nexo-sso.logout'));

    expect(nexoSsoEndSessionQuery($response))->not->toHaveKey('id_token_hint');
    $this->assertGuest();
});

test('AC-LOGOUT-4: with Nexo ID unreachable a 419 on «Cerrar sesión» lands on the login, never the 419 page', function (): void {
    Http::fake(fn () => throw new ConnectionException('provider down'));
    nexoSsoEnforceCsrf($this);

    $this->post(route('nexo-sso.logout'))->assertRedirect(route('login'));
});

test('AC-LOGOUT-4: any other form keeps its 419, and so does a JSON logout', function (): void {
    nexoSsoFakeProvider();
    nexoSsoEnforceCsrf($this);
    Route::middleware('web')->post('/nexo-sso-tests/form', fn () => 'ok');

    $this->post('/nexo-sso-tests/form')->assertStatus(419);
    $this->postJson(route('nexo-sso.logout'))->assertStatus(419);
});

test('AC-LOGOUT-4: with SSO off a 419 on the local logout is the usual page (standalone untouched)', function (): void {
    config(['nexo-sso.enabled' => false]);
    nexoSsoEnforceCsrf($this);

    $this->post(route('logout'))->assertStatus(419);
});

test('AC-LOGOUT-5: a guest «Cerrar sesión» (the session ended before the click) goes to end_session too, in every mode', function (string $mode, string $route): void {
    config(['nexo-sso.auth_mode' => $mode]);
    nexoSsoFakeProvider();

    // In sso mode `auth` would otherwise send this guest through /auth/nexo/enter,
    // signing them straight back in.
    $query = nexoSsoEndSessionQuery($this->post(route($route)));

    expect($query)->not->toHaveKey('id_token_hint')
        ->and($query['client_id'])->toBe('11111111-2222-3333-4444-555555555555');
    $this->assertGuest();
})->with(['local', 'transition', 'sso'])->with(['nexo-sso.logout', 'logout']);
