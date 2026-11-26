<?php

// Back-channel logout, client side (OIDC Back-Channel Logout 1.0, §2.3): Nexo ID
// POSTs a signed logout_token server to server; the tool validates it and
// revokes; NexoSsoSessionGuard ends the matching sessions on their next request.

use App\Http\Middleware\NexoSsoSessionGuard;
use App\Models\User;
use App\Services\NexoSso\NexoSsoRevocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/NexoSsoTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'nexo-sso.enabled' => true,
        'nexo-sso.backchannel' => true,
        'nexo-sso.issuer' => 'https://nexoid.test',
        'nexo-sso.client_id' => '11111111-2222-3333-4444-555555555555',
        'nexo-sso.recheck_minutes' => 15,
    ]);

    Route::middleware(['web', NexoSsoSessionGuard::class, 'auth'])->group(function (): void {
        Route::get('/nexo-sso-tests/page', fn () => response('page-ok'));
        Route::post('/nexo-sso-tests/page', fn () => response('post-ok'));
    });
});

/** POSTs a logout_token the way Nexo ID does (form-encoded, no cookies). */
function nexoSsoSendLogoutToken(string $token): TestResponse
{
    return test()->call('POST', route('nexo-sso.backchannel'), ['logout_token' => $token], [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_ACCEPT' => 'application/json',
    ]);
}

function nexoSsoRevocationCount(): int
{
    return DB::table(NexoSsoRevocations::TABLE)->count();
}

test('AC-BCL-C1: a valid logout_token revokes its sid: 200, empty, no-store', function (): void {
    nexoSsoFakeProvider();

    $response = nexoSsoSendLogoutToken(nexoSsoLogoutToken(['sid' => 'sid-to-end']));

    $response->assertOk();
    expect($response->getContent())->toBe('')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    expect(DB::table(NexoSsoRevocations::TABLE)->where('sid', 'sid-to-end')->exists())->toBeTrue();
});

test('AC-BCL-C1: a kid-less logout_token validates against a single-key JWKS (same path as the id_token)', function (): void {
    nexoSsoFakeProvider();

    nexoSsoSendLogoutToken(nexoSsoLogoutToken([], null, null))->assertOk();
    expect(nexoSsoRevocationCount())->toBe(1);
});

test('AC-BCL-C2: the endpoint is stateless — no session, no CSRF, no cookies', function (): void {
    nexoSsoFakeProvider();

    $response = nexoSsoSendLogoutToken(nexoSsoLogoutToken());

    $response->assertOk();
    expect($response->headers->getCookies())->toBe([]);
});

// Dataset closures run after beforeEach (Pest bound datasets), so they see the
// configured issuer/client and the clock.
test('AC-BCL-C3: an invalid logout_token is rejected with 400 invalid_request and revokes nothing', function (string $token): void {
    nexoSsoFakeProvider();

    nexoSsoSendLogoutToken($token)
        ->assertStatus(400)
        ->assertExactJson(['error' => 'invalid_request'])
        ->assertHeader('Cache-Control', 'no-store, private');
    expect(nexoSsoRevocationCount())->toBe(0);
})->with([
    'foreign signature' => function (): string {
        $foreign = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($foreign, $pem);

        return nexoSsoLogoutToken([], $pem);
    },
    'unsigned (alg=none)' => function (): string {
        $b64 = fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $claims = json_decode(base64_decode(strtr(explode('.', nexoSsoLogoutToken())[1], '-_', '+/')), true);

        return $b64((string) json_encode(['alg' => 'none', 'typ' => 'logout+jwt'])).'.'.$b64((string) json_encode($claims)).'.';
    },
    'another audience' => fn (): string => nexoSsoLogoutToken(['aud' => 'another-client']),
    'another issuer' => fn (): string => nexoSsoLogoutToken(['iss' => 'https://evil.test']),
    'no events' => fn (): string => nexoSsoLogoutToken(['events' => null]),
    'wrong event' => fn (): string => nexoSsoLogoutToken(['events' => ['http://schemas.openid.net/event/other' => new stdClass]]),
    'carries a nonce (an id_token)' => fn (): string => nexoSsoLogoutToken(['nonce' => 'abc']),
    'neither sid nor sub' => fn (): string => nexoSsoLogoutToken(['sid' => null, 'sub' => null]),
    'a sid that is not a string' => fn (): string => nexoSsoLogoutToken(['sid' => 123]),
    'an empty sid' => fn (): string => nexoSsoLogoutToken(['sid' => '']),
    'a sub that is not a string' => fn (): string => nexoSsoLogoutToken(['sid' => null, 'sub' => ['x']]),
    'no jti' => fn (): string => nexoSsoLogoutToken(['jti' => null]),
    'iat 6 minutes old' => fn (): string => nexoSsoLogoutToken(['iat' => now()->getTimestamp() - 360, 'exp' => now()->getTimestamp() + 60]),
    'iat 6 minutes ahead' => fn (): string => nexoSsoLogoutToken(['iat' => now()->getTimestamp() + 360, 'exp' => now()->getTimestamp() + 480]),
    'expired' => fn (): string => nexoSsoLogoutToken(['iat' => now()->getTimestamp() - 130, 'exp' => now()->getTimestamp() - 10]),
    'no exp' => fn (): string => nexoSsoLogoutToken(['exp' => null]),
    'an id_token' => fn (): string => nexoSsoIdToken(),
    'garbage' => fn (): string => 'not-a-jwt',
    'empty' => fn (): string => '',
]);

test('AC-BCL-C3: a small clock skew on iat (under 5 minutes) is tolerated', function (): void {
    nexoSsoFakeProvider();
    $now = now()->getTimestamp();

    nexoSsoSendLogoutToken(nexoSsoLogoutToken(['iat' => $now + 120, 'exp' => $now + 240]))->assertOk();
    nexoSsoSendLogoutToken(nexoSsoLogoutToken(['iat' => $now - 100, 'exp' => $now + 20]))->assertOk();
});

test('AC-BCL-C4: a repeated jti is rejected (replay)', function (): void {
    nexoSsoFakeProvider();
    $jti = (string) Str::uuid();

    nexoSsoSendLogoutToken(nexoSsoLogoutToken(['jti' => $jti]))->assertOk();
    nexoSsoSendLogoutToken(nexoSsoLogoutToken(['jti' => $jti]))->assertStatus(400);

    expect(nexoSsoRevocationCount())->toBe(1);
});

test('AC-BCL-C4: a valid token whose revocation cannot be stored answers 503 and the retry of the same token goes through', function (): void {
    nexoSsoFakeProvider();
    $token = nexoSsoLogoutToken(['sid' => 'sid-to-end']);
    Schema::rename(NexoSsoRevocations::TABLE, 'nexo_sso_revocations_away');

    nexoSsoSendLogoutToken($token)
        ->assertStatus(503)
        ->assertExactJson(['error' => 'temporarily_unavailable'])
        ->assertHeader('Cache-Control', 'no-store, private');

    Schema::rename('nexo_sso_revocations_away', NexoSsoRevocations::TABLE);

    // Nexo ID retries the same token: its jti was released, not burned.
    nexoSsoSendLogoutToken($token)->assertOk();
    expect(DB::table(NexoSsoRevocations::TABLE)->where('sid', 'sid-to-end')->exists())->toBeTrue();
});

test('AC-BCL-C5: a revoked sid ends that session on its next HTML request → signed-out?reason=ended', function (): void {
    $user = User::factory()->create();
    app(NexoSsoRevocations::class)->revoke('nexo-sid-0001', 'user-uuid-0001');

    $this->withSession(nexoSsoSignedIn($this, $user))
        ->get('/nexo-sso-tests/page')
        ->assertRedirect(route('nexo-sso.signed-out', ['reason' => 'ended']));

    $this->assertGuest();
});

test('AC-BCL-C5: a revoked session gets 401 {"reason":"signed_out"} on JSON — POST included', function (): void {
    $user = User::factory()->create();
    app(NexoSsoRevocations::class)->revoke('nexo-sid-0001', null);

    $this->withSession(nexoSsoSignedIn($this, $user))
        ->postJson('/nexo-sso-tests/page')
        ->assertStatus(401)
        ->assertExactJson(['reason' => 'signed_out']);

    $this->assertGuest();
});

test('AC-BCL-C5: revoking another sid leaves this session alone', function (): void {
    $user = User::factory()->create();
    app(NexoSsoRevocations::class)->revoke('some-other-device', 'user-uuid-0001');

    $this->withSession(nexoSsoSignedIn($this, $user))
        ->get('/nexo-sso-tests/page')
        ->assertOk();
    $this->assertAuthenticatedAs($user);
});

test('AC-BCL-C6: a sub-only token ends every session of that person signed in before it, not later ones', function (): void {
    nexoSsoFakeProvider();
    $user = User::factory()->create();
    $before = nexoSsoSignedIn($this, $user);

    nexoSsoSendLogoutToken(nexoSsoLogoutToken(['sid' => null]))->assertOk();

    $this->withSession($before)->get('/nexo-sso-tests/page')
        ->assertRedirect(route('nexo-sso.signed-out', ['reason' => 'ended']));
    $this->assertGuest();

    // A session that signed in after the revocation is not affected.
    $this->travel(5)->seconds();
    $after = nexoSsoSignedIn($this, $user, extra: [
        'nexo_sso.sid' => 'a-new-device',
        'nexo_sso.authenticated_at' => now()->getTimestamp(),
    ]);
    $this->withSession($after)->get('/nexo-sso-tests/page')->assertOk();
});

test('AC-BCL-C6: a sub-only token also ends a session opened before v2 (no sid, no authenticated_at)', function (): void {
    nexoSsoFakeProvider();
    $user = User::factory()->create();
    $user->forceFill(['nexo_id_sub' => 'user-uuid-0001'])->save();

    nexoSsoSendLogoutToken(nexoSsoLogoutToken(['sid' => null]))->assertOk();

    $this->actingAs($user)->withSession(['nexo_sso.id_token' => 'pre-v2'])
        ->get('/nexo-sso-tests/page')
        ->assertRedirect(route('nexo-sso.signed-out', ['reason' => 'ended']));
});

test('AC-BCL-C7: with NEXO_SSO_BACKCHANNEL=false the endpoint answers 501 and the guard ignores revocations', function (): void {
    config(['nexo-sso.backchannel' => false]);
    nexoSsoFakeProvider();
    $user = User::factory()->create();
    app(NexoSsoRevocations::class)->revoke('nexo-sid-0001', null);

    nexoSsoSendLogoutToken(nexoSsoLogoutToken())->assertStatus(501);

    $this->withSession(nexoSsoSignedIn($this, $user))->get('/nexo-sso-tests/page')->assertOk();
});

test('AC-BCL-C8: revocations expire after revocation_ttl_hours and nexo-sso:prune deletes them', function (): void {
    $user = User::factory()->create();
    app(NexoSsoRevocations::class)->revoke('nexo-sid-0001', null);

    $this->travel(25)->hours();

    $this->withSession(nexoSsoSignedIn($this, $user, checkedAgo: 60))->get('/nexo-sso-tests/page')->assertOk();

    $this->artisan('nexo-sso:prune')->assertSuccessful();
    expect(nexoSsoRevocationCount())->toBe(0);
});

test('AC-BCL-C8: prune keeps the revocations that are still live', function (): void {
    app(NexoSsoRevocations::class)->revoke('nexo-sid-0001', null);

    $this->artisan('nexo-sso:prune')->assertSuccessful();

    expect(nexoSsoRevocationCount())->toBe(1);
});

test('AC-BCL-C9: the endpoint has its own throttle, 60 per minute per IP', function (): void {
    nexoSsoFakeProvider();

    for ($i = 0; $i < 60; $i++) {
        nexoSsoSendLogoutToken('not-a-jwt')->assertStatus(400);
    }

    nexoSsoSendLogoutToken(nexoSsoLogoutToken())->assertStatus(429);

    $this->travel(61)->seconds();
    nexoSsoSendLogoutToken(nexoSsoLogoutToken())->assertOk();
});
