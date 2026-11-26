<?php

use App\Http\Middleware\NexoSsoSilentLogin;
use App\Models\User;
use App\Services\NexoSso\NexoSsoFlows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

require_once __DIR__.'/NexoSsoTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'nexo-sso.enabled' => true,
        'nexo-sso.silent' => true,
        'nexo-sso.issuer' => 'https://nexoid.test',
        'nexo-sso.client_id' => '11111111-2222-3333-4444-555555555555',
    ]);

    // Throwaway guest pages carrying the middleware, so these tests don't
    // depend on any concrete route of the consuming tool. Multi-segment paths:
    // a tool's single-segment slug catch-all (/{username}) must never swallow them.
    Route::middleware(['web', NexoSsoSilentLogin::class])->get('/nexo-sso-tests/probe', fn () => response('silent-probe-ok'));
    Route::middleware(['web', NexoSsoSilentLogin::class])->post('/nexo-sso-tests/probe', fn () => response('silent-probe-ok'));
});

/** A request that presents this app's session cookie (any value: presence is what counts). */
function nexoSsoWithSessionCookie(TestCase $test): TestCase
{
    return $test->withCookie((string) config('session.cookie'), 'previously-issued');
}

test('AC-SILENT-1: a guest page hands an eligible visitor to the silent attempt, marking it once-per-session', function (): void {
    $response = nexoSsoWithSessionCookie($this)->get('/nexo-sso-tests/probe?tab=2');

    // The page travels with the attempt, so the visitor returns exactly there;
    // url.intended is left alone (it belongs to `auth`).
    $response->assertRedirect(route('nexo-sso.silent', ['to' => '/nexo-sso-tests/probe?tab=2']));
    $response->assertSessionHas('nexo_sso.silent_attempted', true);
    expect(session('url.intended'))->toBeNull();
});

test('AC-SILENT-1: the silent authorize request is the standard one (PKCE+state+nonce) plus prompt=none', function (): void {
    nexoSsoFakeProvider();

    $response = $this->get(route('nexo-sso.silent', ['to' => '/nexo-sso-tests/probe']));

    $response->assertRedirect();
    $query = nexoSsoAuthorizeQuery($response);
    $flow = nexoSsoStoredFlow($query['state']);

    expect($query['prompt'])->toBe('none')
        ->and($query['response_type'])->toBe('code')
        ->and($query['redirect_uri'])->toBe(route('nexo-sso.callback'))
        ->and($query['nonce'])->toBe($flow['nonce'])
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe(
            rtrim(strtr(base64_encode(hash('sha256', $flow['verifier'], true)), '+/', '-_'), '=')
        );

    // The flow is saved as silent, so the callback picks the silent UX, and
    // it remembers the page it started from.
    expect($flow['mode'])->toBe(NexoSsoFlows::SILENT)
        ->and($flow['return'])->toBe('/nexo-sso-tests/probe');
    $response->assertSessionHas('nexo_sso.silent_attempted', true);
});

test('AC-SILENT-1: an unsafe `to` never reaches the silent flow', function (): void {
    nexoSsoFakeProvider();

    $query = nexoSsoAuthorizeQuery($this->get(route('nexo-sso.silent', ['to' => '//evil.test/x'])));

    expect(nexoSsoStoredFlow($query['state'])['return'])->toBeNull();
});

test('AC-SILENT-1: a silent callback carrying a code signs the user in and returns to the page it started from', function (): void {
    nexoSsoFakeProvider(); // default id_token echoes the session nonce

    $response = $this
        ->withSession(nexoSsoFlowSession(NexoSsoFlows::SILENT, '/target-page') + ['nexo_sso.silent_attempted' => true])
        ->get(route('nexo-sso.callback', ['code' => 'auth-code', 'state' => nexoSsoState()]));

    $this->assertAuthenticated();
    $response->assertRedirect(url('/target-page'));
});

test('AC-SILENT-2: login_required after a silent attempt stays guest, shows no error and returns to the destination', function (): void {
    Http::fake();

    $response = $this
        ->withSession(nexoSsoFlowSession(NexoSsoFlows::SILENT, '/target-page') + ['nexo_sso.silent_attempted' => true])
        ->get(route('nexo-sso.callback', ['error' => 'login_required', 'state' => nexoSsoState()]));

    $this->assertGuest();
    $response->assertRedirect(url('/target-page'));
    $response->assertSessionHasNoErrors();
    // The once-per-session mark survives — no retry this session.
    $response->assertSessionHas('nexo_sso.silent_attempted', true);
    Http::assertNothingSent(); // an error response never triggers provider calls
});

test('AC-SILENT-2: interaction_required is handled the same silent way', function (): void {
    Http::fake();

    $response = $this
        ->withSession(nexoSsoFlowSession(NexoSsoFlows::SILENT, '/target-page') + ['nexo_sso.silent_attempted' => true])
        ->get(route('nexo-sso.callback', ['error' => 'interaction_required', 'state' => nexoSsoState()]));

    $this->assertGuest();
    $response->assertRedirect(url('/target-page'));
    $response->assertSessionHasNoErrors();
    Http::assertNothingSent();
});

test('AC-SILENT-2: the next pageload after a failed attempt does not redirect again (no loop)', function (): void {
    nexoSsoWithSessionCookie($this)
        ->withSession(['nexo_sso.silent_attempted' => true])
        ->get('/nexo-sso-tests/probe')
        ->assertOk()
        ->assertSee('silent-probe-ok');
});

test('AC-SILENT-2: an interactive (non-silent) error callback still surfaces the error', function (): void {
    nexoSsoErrorCallback($this, 'access_denied', NexoSsoFlows::INTERACTIVE)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();
});

test('AC-SILENT-3: with SSO disabled the middleware is a pass-through', function (): void {
    config(['nexo-sso.enabled' => false]);

    nexoSsoWithSessionCookie($this)->get('/nexo-sso-tests/probe')
        ->assertOk()
        ->assertSessionMissing('nexo_sso.silent_attempted');
});

test('AC-SILENT-3: the NEXO_SSO_SILENT kill-switch alone disables the attempt (and its route)', function (): void {
    config(['nexo-sso.silent' => false]);

    nexoSsoWithSessionCookie($this)->get('/nexo-sso-tests/probe')
        ->assertOk()
        ->assertSessionMissing('nexo_sso.silent_attempted');

    $this->get(route('nexo-sso.silent'))->assertNotFound();
});

test('AC-SILENT-4: a request without this app\'s session cookie never bounces (bots, monitors, first hit)', function (): void {
    $this->get('/nexo-sso-tests/probe')
        ->assertOk()
        ->assertSee('silent-probe-ok');
});

test('AC-SILENT-4: only guest GET page navigations are eligible', function (): void {
    // Authenticated: nothing to do.
    nexoSsoWithSessionCookie($this->actingAs(User::factory()->create()))
        ->get('/nexo-sso-tests/probe')->assertOk();

    // Non-GET: never redirected.
    nexoSsoWithSessionCookie($this)->post('/nexo-sso-tests/probe')->assertOk();

    // JSON/XHR: redirecting an API-ish request would break it.
    nexoSsoWithSessionCookie($this)->getJson('/nexo-sso-tests/probe')->assertOk();
});

test('AC-SILENT-4: excluded paths never attempt (public storefronts, machine endpoints)', function (): void {
    config(['nexo-sso.silent_excluded' => ['nexo-sso-tests/*']]);

    nexoSsoWithSessionCookie($this)->get('/nexo-sso-tests/probe')
        ->assertOk()
        ->assertSessionMissing('nexo_sso.silent_attempted');
});

test('AC-SILENT-4: excluded route NAMES never attempt (slug catch-alls)', function (): void {
    Route::middleware(['web', NexoSsoSilentLogin::class])
        ->get('/nexo-sso-tests/probe-named', fn () => response('named-ok'))
        ->name('silent.probe.named');
    config(['nexo-sso.silent_excluded_routes' => ['silent.probe.*']]);

    nexoSsoWithSessionCookie($this)->get('/nexo-sso-tests/probe-named')
        ->assertOk()
        ->assertSessionMissing('nexo_sso.silent_attempted');
});

test('AC-SILENT-11: the fallback route (an unknown URL, the 404 page) never attempts, with no config at all', function (): void {
    config(['nexo-sso.silent_excluded' => [], 'nexo-sso.silent_excluded_routes' => []]);
    Route::fallback(fn () => response('fallback-404', 404))->middleware(['web', NexoSsoSilentLogin::class]);

    $response = nexoSsoWithSessionCookie($this)->get('/nexo-sso-tests/no/such/page');

    $response->assertNotFound()->assertSessionMissing('nexo_sso.silent_attempted');
    expect($response->headers->get('Location'))->toBeNull();

    // Control: a real page of the same shape does attempt.
    nexoSsoWithSessionCookie($this)->get('/nexo-sso-tests/probe')->assertRedirect();
});

test('AC-SILENT-5: logout marks the fresh session so silent SSO cannot immediately sign the user back in', function (): void {
    nexoSsoFakeProvider();

    $response = $this->withSession(nexoSsoSignedIn($this, User::factory()->create()))->post(route('nexo-sso.logout'));

    $response->assertRedirect(); // RP-initiated logout redirect (end_session)
    $this->assertGuest();
    // The fresh (post-invalidate) session already carries the mark: the next
    // guest pageload serves plainly instead of silently re-authenticating.
    $response->assertSessionHas('nexo_sso.silent_attempted', true);
});

test('AC-SILENT-6: an intended destination already saved (the deep link auth stored) is kept, not replaced by this page', function (): void {
    $response = nexoSsoWithSessionCookie($this)
        ->withSession(['url.intended' => url('/deep/link?tab=2')])
        ->get('/nexo-sso-tests/probe');

    $response->assertRedirect(route('nexo-sso.silent', ['to' => '/nexo-sso-tests/probe']));
    expect(session('url.intended'))->toBe(url('/deep/link?tab=2'));
});

test('AC-SILENT-6: the silent round trip then lands on that saved destination', function (): void {
    nexoSsoFakeProvider();

    $this->withSession(nexoSsoFlowSession(NexoSsoFlows::SILENT, '/nexo-sso-tests/probe') + ['url.intended' => url('/deep/link')])
        ->get(route('nexo-sso.callback', ['code' => 'auth-code', 'state' => nexoSsoState()]))
        ->assertRedirect(url('/deep/link'));
    $this->assertAuthenticated();
});

test('AC-SILENT-10: a failed attempt returns to the page it started from and keeps a saved destination for the real sign-in', function (string $error): void {
    Http::fake();

    // The guest abandoned a sign-in (auth saved /deep/link) and opened a public
    // page: no Nexo ID session must not send them to that deep link — it is
    // protected, so they would be bounced straight into a sign-in.
    $this->withSession(nexoSsoFlowSession(NexoSsoFlows::SILENT, '/nexo-sso-tests/probe') + ['url.intended' => url('/deep/link')])
        ->get(route('nexo-sso.callback', ['error' => $error, 'state' => nexoSsoState()]))
        ->assertRedirect(url('/nexo-sso-tests/probe'));

    $this->assertGuest();
    expect(session('url.intended'))->toBe(url('/deep/link'));
})->with(['login_required', 'interaction_required', 'server_error']);

test('AC-SILENT-10: with the provider unreachable the attempt goes back to the page, no error UI', function (): void {
    Http::fake(fn () => throw new ConnectionException('provider down'));

    $this->withSession(['url.intended' => url('/deep/link')])
        ->get(route('nexo-sso.silent', ['to' => '/nexo-sso-tests/probe']))
        ->assertRedirect(url('/nexo-sso-tests/probe'))
        ->assertSessionHasNoErrors();

    expect(session('url.intended'))->toBe(url('/deep/link'));
});

test('AC-SILENT-10: a direct hit without `to` that fails lands on the public root, never the panel', function (): void {
    Http::fake();

    $this->withSession(nexoSsoFlowSession(NexoSsoFlows::SILENT))
        ->get(route('nexo-sso.callback', ['error' => 'login_required', 'state' => nexoSsoState()]))
        ->assertRedirect(url('/'));
});

test('AC-SILENT-7: the auth screens never attempt (login, register, password.*, verification.*, auth/nexo/*)', function (string $name): void {
    Route::middleware(['web', NexoSsoSilentLogin::class])
        ->get('/nexo-sso-tests/auth-screen-'.str_replace('.', '-', $name), fn () => response('auth-screen-ok'))
        ->name($name);
    app('router')->getRoutes()->refreshNameLookups();

    nexoSsoWithSessionCookie($this)->get('/nexo-sso-tests/auth-screen-'.str_replace('.', '-', $name))
        ->assertOk()
        ->assertSessionMissing('nexo_sso.silent_attempted');
})->with(['login', 'register', 'password.request', 'password.reset', 'verification.notice', 'nexo-sso.signed-out']);

test('AC-SILENT-8: a request carrying validation errors back to a form never attempts', function (): void {
    nexoSsoWithSessionCookie($this)
        ->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['email' => 'wrong']))])
        ->get('/nexo-sso-tests/probe')
        ->assertOk()
        ->assertSessionMissing('nexo_sso.silent_attempted');
});

// One request per case: test headers persist across requests in a test, so
// two cases in one test would let the first header mask the second.
test('AC-SILENT-9: a prefetch or a non-navigation fetch never attempts', function (string $header, string $value): void {
    nexoSsoWithSessionCookie($this)->withHeader($header, $value)->get('/nexo-sso-tests/probe')
        ->assertOk()
        ->assertSessionMissing('nexo_sso.silent_attempted');
})->with([
    'speculative prefetch' => ['Sec-Purpose', 'prefetch'],
    'legacy prefetch' => ['Purpose', 'prefetch'],
    'fetch()' => ['Sec-Fetch-Mode', 'cors'],
    'subresource' => ['Sec-Fetch-Mode', 'no-cors'],
]);

test('AC-SILENT-9: a real navigation (Sec-Fetch-Mode: navigate) does attempt', function (): void {
    nexoSsoWithSessionCookie($this)->withHeader('Sec-Fetch-Mode', 'navigate')->get('/nexo-sso-tests/probe')
        ->assertRedirect(route('nexo-sso.silent', ['to' => '/nexo-sso-tests/probe']));
});
