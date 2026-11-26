<?php

// Periodic re-validation (§2.4, option C of the session analysis): Nexo ID
// controls how long a tool session lives. NexoSsoSessionGuard bounces a GET page
// navigation through /auth/nexo/recheck (prompt=none) when the last check is
// older than NEXO_SSO_RECHECK_MINUTES; the callback renews, or closes the
// session. Never POST, never JSON; fail-open when Nexo ID does not answer.

use App\Http\Middleware\NexoSsoSessionGuard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/NexoSsoTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'nexo-sso.enabled' => true,
        'nexo-sso.issuer' => 'https://nexoid.test',
        'nexo-sso.client_id' => '11111111-2222-3333-4444-555555555555',
        'nexo-sso.recheck_minutes' => 15,
        'nexo-sso.auth_mode' => 'local',
    ]);

    // Throwaway signed-in pages behind the guard (also appended to `web` by the
    // install; running twice is harmless).
    Route::middleware(['web', NexoSsoSessionGuard::class, 'auth'])->group(function (): void {
        Route::get('/nexo-sso-tests/page', fn () => response('page-ok'));
        Route::post('/nexo-sso-tests/page', fn () => response('post-ok'));
        Route::get('/nexo-sso-tests/public', fn () => response('public-ok'))->name('nexo-sso-tests.public');
    });
});

/** The nonce of the re-validation flow $state, as the controller stored it. */
function nexoSsoRecheckNonce(string $state): string
{
    return (string) (Cache::get('nexo-sso.flow.'.hash('sha256', $state))['nonce'] ?? '');
}

/** Fake provider whose token endpoint answers the given id_tokens in order. */
function nexoSsoProviderWithTokens(array $idTokens): void
{
    $issuer = config('nexo-sso.issuer');
    $sequence = Http::sequence();
    foreach ($idTokens as $token) {
        $sequence->push(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => $token]);
    }

    Http::fake([
        $issuer.'/.well-known/openid-configuration' => Http::response([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'jwks_uri' => $issuer.'/oauth/jwks',
            'end_session_endpoint' => $issuer.'/oauth/logout',
        ]),
        $issuer.'/oauth/jwks' => Http::response(['keys' => [nexoSsoKeypair()['jwk']]]),
        $issuer.'/oauth/token' => $sequence,
    ]);
}

test('AC-RECHECK-1: an old check bounces a GET page to recheck, prompt=none, and comes back to the same URL renewed', function (): void {
    nexoSsoFakeProvider();
    $user = User::factory()->create();
    $session = nexoSsoSignedIn($this, $user, checkedAgo: 16 * 60, extra: ['nexo_sso.sid' => 'old-sid']);

    // 1. The page bounces (Nexo ID answers the probe), carrying itself as `to`.
    $this->withSession($session)->get('/nexo-sso-tests/page?tab=2')
        ->assertRedirect(route('nexo-sso.recheck', ['to' => '/nexo-sso-tests/page?tab=2']));

    // 2. recheck → authorize with prompt=none.
    $toProvider = $this->get(route('nexo-sso.recheck', ['to' => '/nexo-sso-tests/page?tab=2']));
    $query = nexoSsoAuthorizeQuery($toProvider);
    expect((string) $toProvider->headers->get('Location'))->toStartWith('https://nexoid.test/oauth/authorize?')
        ->and($query['prompt'])->toBe('none');

    // 3. Same person → back to the page, check renewed, sid adopted.
    Http::swap(new Factory);
    nexoSsoProviderWithTokens([nexoSsoIdToken(['nonce' => nexoSsoRecheckNonce($query['state']), 'sid' => 'nexo-sid-new'])]);
    $this->travel(1)->seconds();

    $this->get(route('nexo-sso.callback', ['code' => 'auth-code', 'state' => $query['state']]))
        ->assertRedirect(url('/nexo-sso-tests/page?tab=2'));

    $this->assertAuthenticatedAs($user);
    expect(session('nexo_sso.checked_at'))->toBe(now()->getTimestamp())
        ->and(session('nexo_sso.sid'))->toBe('nexo-sid-new')
        ->and(session('nexo_sso.authenticated_at'))->toBe($session['nexo_sso.authenticated_at']);

    // 4. The next navigation serves normally.
    $this->get('/nexo-sso-tests/page')->assertOk()->assertSee('page-ok');
});

test('AC-RECHECK-1: a recent check serves the page without asking anyone', function (): void {
    Http::fake();
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: 5 * 60))
        ->get('/nexo-sso-tests/page')
        ->assertOk()
        ->assertSee('page-ok');

    Http::assertNothingSent();
});

test('AC-RECHECK-2: POST and JSON are never bounced, however old the check', function (): void {
    nexoSsoFakeProvider();
    $user = User::factory()->create();
    $session = nexoSsoSignedIn($this, $user, checkedAgo: 3600);

    $this->withSession($session)->post('/nexo-sso-tests/page')->assertOk()->assertSee('post-ok');
    $this->withSession($session)->getJson('/nexo-sso-tests/page')->assertOk();
    $this->withSession($session)->get('/nexo-sso-tests/page', ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('AC-RECHECK-3: login_required closes the local session → "Your Nexo ID session ended"', function (string $error): void {
    Http::fake();
    $user = User::factory()->create();
    nexoSsoRecheckFlow($user, '/nexo-sso-tests/page');

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: 3600))
        ->get(route('nexo-sso.callback', ['error' => $error, 'state' => nexoSsoState()]))
        ->assertRedirect(route('nexo-sso.signed-out', ['reason' => 'ended']));

    $this->assertGuest();
    expect(session('nexo_sso.sub'))->toBeNull();
    Http::assertNothingSent();
})->with(['login_required', 'interaction_required']);

test('AC-RECHECK-4: Nexo ID answering as another person closes the local session', function (): void {
    $user = User::factory()->create();
    nexoSsoRecheckFlow($user, '/nexo-sso-tests/page');
    nexoSsoFakeProvider(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => nexoSsoIdToken(['sub' => 'someone-else'])]);

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: 3600))
        ->get(route('nexo-sso.callback', ['code' => 'auth-code', 'state' => nexoSsoState()]))
        ->assertRedirect(route('nexo-sso.signed-out', ['reason' => 'ended']));

    $this->assertGuest();
});

test('AC-RECHECK-5: with Nexo ID unreachable nobody is bounced or signed out', function (): void {
    Http::fake(fn () => throw new ConnectionException('provider down'));
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: 3600))
        ->get('/nexo-sso-tests/page')
        ->assertOk()
        ->assertSee('page-ok');
    $this->assertAuthenticatedAs($user);
});

test('AC-RECHECK-5: with Nexo ID failing, the probe is cached and retried after 5 minutes', function (): void {
    Http::fake(['*' => Http::response('down', 503)]);
    $user = User::factory()->create();
    $session = nexoSsoSignedIn($this, $user, checkedAgo: 3600);

    $this->withSession($session)->get('/nexo-sso-tests/page')->assertOk()->assertSee('page-ok');
    $this->get('/nexo-sso-tests/page')->assertOk();
    expect(Http::recorded())->toHaveCount(1); // one probe, then the cached "down"

    $this->travel(301)->seconds();
    $this->get('/nexo-sso-tests/page')->assertOk();
    expect(Http::recorded())->toHaveCount(2);

    $this->assertAuthenticatedAs($user);
});

test('AC-RECHECK-5: with Nexo ID unreachable when the bounce starts, recheck goes back to the page and waits 5 minutes', function (): void {
    $user = User::factory()->create();
    $session = nexoSsoSignedIn($this, $user, checkedAgo: 3600);
    Http::fake(fn () => throw new ConnectionException('provider down'));

    $this->withSession($session)
        ->get(route('nexo-sso.recheck', ['to' => '/nexo-sso-tests/page']))
        ->assertRedirect(url('/nexo-sso-tests/page'));
    $this->assertAuthenticatedAs($user);

    // The probe answers again, but this session already deferred its
    // re-validation — past the 30 s in-flight window, too.
    Http::swap(new Factory);
    nexoSsoFakeProvider();
    Cache::forget('nexo-sso.probe');
    $this->travel(31)->seconds();
    $this->get('/nexo-sso-tests/page')->assertOk();

    $this->travel(270)->seconds();
    $this->get('/nexo-sso-tests/page')->assertRedirect(route('nexo-sso.recheck', ['to' => '/nexo-sso-tests/page']));
});

test('AC-RECHECK-5: a revocation lookup that cannot run (table missing mid-deploy) fails open, never a 500', function (): void {
    $user = User::factory()->create();
    Schema::drop('nexo_sso_revocations');

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: 60))
        ->get('/nexo-sso-tests/page')
        ->assertOk()
        ->assertSee('page-ok');
    $this->assertAuthenticatedAs($user);
});

test('AC-RECHECK-6: NEXO_SSO_RECHECK_MINUTES=0 turns re-validation off', function (): void {
    config(['nexo-sso.recheck_minutes' => 0]);
    Http::fake();
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: null))
        ->get('/nexo-sso-tests/page')
        ->assertOk();

    $this->get(route('nexo-sso.recheck', ['to' => '/nexo-sso-tests/page']))
        ->assertRedirect(url('/nexo-sso-tests/page'));
    Http::assertNothingSent();
});

test('AC-RECHECK-7: a session opened before v2 (id_token only, no checked_at nor sid) re-validates and adopts sub and sid', function (): void {
    nexoSsoFakeProvider();
    $user = User::factory()->create();
    $user->forceFill(['nexo_id_sub' => 'user-uuid-0001'])->save();
    $this->actingAs($user);

    $this->withSession(['nexo_sso.id_token' => 'pre-v2-id-token'])
        ->get('/nexo-sso-tests/page')
        ->assertRedirect(route('nexo-sso.recheck', ['to' => '/nexo-sso-tests/page']));

    nexoSsoRecheckFlow($user, '/nexo-sso-tests/page');

    $this->get(route('nexo-sso.callback', ['code' => 'auth-code', 'state' => nexoSsoState()]))
        ->assertRedirect(url('/nexo-sso-tests/page'));

    $this->assertAuthenticatedAs($user);
    expect(session('nexo_sso.sub'))->toBe('user-uuid-0001')
        ->and(session('nexo_sso.sid'))->toBe('nexo-sid-0001')
        ->and(session('nexo_sso.checked_at'))->toBe(now()->getTimestamp())
        ->and(session('nexo_sso.authenticated_at'))->toBe(now()->getTimestamp());
});

test('AC-RECHECK-8: two tabs re-validating at once keep their own state and each returns to its own page', function (): void {
    $user = User::factory()->create();
    nexoSsoFakeProvider();
    $session = nexoSsoSignedIn($this, $user, checkedAgo: 3600);

    $a = nexoSsoAuthorizeQuery($this->withSession($session)->get(route('nexo-sso.recheck', ['to' => '/nexo-sso-tests/page?tab=a'])));
    $b = nexoSsoAuthorizeQuery($this->get(route('nexo-sso.recheck', ['to' => '/nexo-sso-tests/page?tab=b'])));
    expect($a['state'])->not->toBe($b['state']);

    Http::swap(new Factory);
    nexoSsoProviderWithTokens([
        nexoSsoIdToken(['nonce' => nexoSsoRecheckNonce($b['state'])]),
        nexoSsoIdToken(['nonce' => nexoSsoRecheckNonce($a['state'])]),
    ]);

    // B answers first, then A: neither overwrote the other.
    $this->get(route('nexo-sso.callback', ['code' => 'code-b', 'state' => $b['state']]))
        ->assertRedirect(url('/nexo-sso-tests/page?tab=b'));
    $this->get(route('nexo-sso.callback', ['code' => 'code-a', 'state' => $a['state']]))
        ->assertRedirect(url('/nexo-sso-tests/page?tab=a'));

    $this->assertAuthenticatedAs($user);
});

test('AC-RECHECK-8: while one tab is bouncing, other tabs are not bounced again', function (): void {
    nexoSsoFakeProvider();
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: 3600))
        ->get(route('nexo-sso.recheck', ['to' => '/nexo-sso-tests/page']))
        ->assertRedirect();

    $this->get('/nexo-sso-tests/page')->assertOk()->assertSee('page-ok');

    $this->travel(31)->seconds();
    $this->get('/nexo-sso-tests/page')->assertRedirect(route('nexo-sso.recheck', ['to' => '/nexo-sso-tests/page']));
});

test('AC-RECHECK-9: any other provider error keeps the session and retries later', function (): void {
    nexoSsoFakeProvider();
    $user = User::factory()->create();
    nexoSsoRecheckFlow($user, '/nexo-sso-tests/page');

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: 3600))
        ->get(route('nexo-sso.callback', ['error' => 'server_error', 'state' => nexoSsoState()]))
        ->assertRedirect(url('/nexo-sso-tests/page'));

    $this->assertAuthenticatedAs($user);
    $this->get('/nexo-sso-tests/page')->assertOk(); // deferred: no bounce for 5 min

    $this->travel(301)->seconds();
    $this->get('/nexo-sso-tests/page')->assertRedirect(route('nexo-sso.recheck', ['to' => '/nexo-sso-tests/page']));
});

test('AC-RECHECK-9: a token exchange that fails keeps the session (fail-open)', function (): void {
    $user = User::factory()->create();
    nexoSsoRecheckFlow($user, '/nexo-sso-tests/page');
    nexoSsoFakeProvider(['error' => 'server_error'], [], 500);

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: 3600))
        ->get(route('nexo-sso.callback', ['code' => 'auth-code', 'state' => nexoSsoState()]))
        ->assertRedirect(url('/nexo-sso-tests/page'));

    $this->assertAuthenticatedAs($user);
});

test('AC-RECHECK-10: a forged callback (unknown state) never signs anyone out', function (): void {
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: 3600))
        ->get(route('nexo-sso.callback', ['error' => 'login_required', 'state' => 'forged-state']))
        ->assertRedirect(url(config('nexo-sso.home')));

    $this->assertAuthenticatedAs($user);
});

test('AC-RECHECK-10: a re-validation flow can only be redeemed by the user who started it', function (): void {
    $owner = User::factory()->create();
    nexoSsoRecheckFlow($owner, '/nexo-sso-tests/page');
    $other = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $other, checkedAgo: 60))
        ->get(route('nexo-sso.callback', ['error' => 'login_required', 'state' => nexoSsoState()]))
        ->assertRedirect(url(config('nexo-sso.home')));

    $this->assertAuthenticatedAs($other);
});

test('AC-RECHECK-11: public surfaces listed in the silent exclusions are never bounced', function (): void {
    config(['nexo-sso.silent_excluded_routes' => ['nexo-sso-tests.public']]);
    Http::fake();
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: 3600))
        ->get('/nexo-sso-tests/public')
        ->assertOk()
        ->assertSee('public-ok');
    Http::assertNothingSent();
});

test('AC-RECHECK-11: the fallback route (the 404 page) is never bounced either', function (): void {
    Route::fallback(fn () => response('fallback-404', 404))->middleware(['web', NexoSsoSessionGuard::class]);
    Http::fake();
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: 3600))
        ->get('/nexo-sso-tests/no/such/page')
        ->assertNotFound()
        ->assertSee('fallback-404');
    Http::assertNothingSent();
});

test('AC-RECHECK-12: in sso mode a session Nexo ID does not govern is closed and re-entered through Nexo ID', function (): void {
    config(['nexo-sso.auth_mode' => 'sso']);
    $user = User::factory()->create();

    $this->actingAs($user)->get('/nexo-sso-tests/page?x=1')
        ->assertRedirect(route('nexo-sso.enter', ['to' => '/nexo-sso-tests/page?x=1']));
    $this->assertGuest();
});

test('AC-RECHECK-12: in sso mode such a session gets 401 signed_out on JSON', function (): void {
    config(['nexo-sso.auth_mode' => 'sso']);

    $this->actingAs(User::factory()->create())
        ->getJson('/nexo-sso-tests/page')
        ->assertStatus(401)
        ->assertExactJson(['reason' => 'signed_out']);
    $this->assertGuest();
});

test('AC-RECHECK-12: in sso mode such a session sending a form (not a page navigation) is closed and lands on signed-out, not back in', function (): void {
    config(['nexo-sso.auth_mode' => 'sso']);
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->post('/nexo-sso-tests/page')
        ->assertRedirect(route('nexo-sso.signed-out', ['reason' => 'ended']));
    $this->assertGuest();
    Http::assertNothingSent();
});

test('AC-RECHECK-12: in sso mode such a session on a public excluded surface is closed and served as a guest', function (): void {
    config(['nexo-sso.auth_mode' => 'sso', 'nexo-sso.silent_excluded_routes' => ['nexo-sso-tests.storefront']]);
    Route::middleware(['web', NexoSsoSessionGuard::class])
        ->get('/nexo-sso-tests/storefront', fn () => response(auth()->check() ? 'storefront-member' : 'storefront-guest'))
        ->name('nexo-sso-tests.storefront');
    app('router')->getRoutes()->refreshNameLookups();

    $this->actingAs(User::factory()->create())
        ->get('/nexo-sso-tests/storefront')
        ->assertOk()
        ->assertSee('storefront-guest');
    $this->assertGuest();
});

test('AC-RECHECK-12: in transition a password session of an account already linked to Nexo ID is closed too', function (): void {
    config(['nexo-sso.auth_mode' => 'transition', 'nexo-sso.transition_until' => now()->addMonth()->format('Y-m-d')]);
    $user = User::factory()->create();
    $user->forceFill(['nexo_id_sub' => 'linked-sub'])->save();

    $this->actingAs($user)->get('/nexo-sso-tests/page')
        ->assertRedirect(route('nexo-sso.enter', ['to' => '/nexo-sso-tests/page']));
    $this->assertGuest();
});

test('AC-RECHECK-12: in local and transition modes a password session is left alone', function (string $mode): void {
    config(['nexo-sso.auth_mode' => $mode]);
    Http::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/nexo-sso-tests/page')->assertOk();
    $this->assertAuthenticatedAs($user);
    Http::assertNothingSent();
})->with(['local', 'transition']);

test('AC-RECHECK-12: in local mode a password session of a linked account is left alone', function (): void {
    Http::fake();
    $user = User::factory()->create();
    $user->forceFill(['nexo_id_sub' => 'linked-sub'])->save();

    $this->actingAs($user)->get('/nexo-sso-tests/page')->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('AC-RECHECK-14: recheck needs a session — a guest never starts a re-validation', function (): void {
    Http::fake();

    $this->get(route('nexo-sso.recheck', ['to' => '/nexo-sso-tests/page']))
        ->assertRedirect(route('login'));
    Http::assertNothingSent();
});

test('AC-RECHECK-13: with SSO off the guard is a pass-through', function (): void {
    config(['nexo-sso.enabled' => false]);
    Http::fake();
    $user = User::factory()->create();

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: null))
        ->get('/nexo-sso-tests/page')
        ->assertOk();
    Http::assertNothingSent();
});
