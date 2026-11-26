<?php

// JWKS and key rotation (§0.1 item 3, v2.1): when a token's signature doesn't
// verify against the cached JWKS, or its kid isn't there, the client fetches
// the JWKS again once and retries — at most once a minute for the whole app —
// for id_tokens and logout_tokens alike. A rotation at Nexo ID then never cuts
// the sign-in nor the back channel for an hour (the JWKS cache). (AC-JWKS)

use App\Services\NexoSso\NexoSsoException;
use App\Services\NexoSso\NexoSsoService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
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

/**
 * A provider whose JWKS is whatever $keys holds at the time of each request
 * (by reference: the test rotates it), or a connection failure when $down.
 *
 * @param  array<int, array<string, string>>  $keys
 */
function nexoSsoRotatingProvider(array &$keys, bool &$down, ?string $idToken = null): void
{
    $issuer = config('nexo-sso.issuer');

    Http::fake(function (HttpRequest $request) use (&$keys, &$down, $issuer, $idToken) {
        $url = $request->url();

        if (str_ends_with($url, '/.well-known/openid-configuration')) {
            return Http::response([
                'issuer' => $issuer,
                'authorization_endpoint' => $issuer.'/oauth/authorize',
                'token_endpoint' => $issuer.'/oauth/token',
                'jwks_uri' => $issuer.'/oauth/jwks',
                'end_session_endpoint' => $issuer.'/oauth/logout',
            ]);
        }

        if (str_ends_with($url, '/oauth/jwks')) {
            if ($down) {
                throw new ConnectionException('provider down');
            }

            return Http::response(['keys' => $keys]);
        }

        return Http::response(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => $idToken ?? nexoSsoIdToken()]);
    });
}

function nexoSsoJwksFetches(): int
{
    return count(Http::recorded(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/oauth/jwks')));
}

/** An id_token signed with the rotated key "rotated-1" (kid-less, like Nexo ID, unless $kid). */
function nexoSsoRotatedIdToken(?string $kid = null, string $key = 'rotated-1', array $claims = []): string
{
    return nexoSsoIdToken($claims, nexoSsoExtraKeypair($key)['private'], $kid);
}

test('AC-JWKS-1: a kid-less id_token signed with a rotated key verifies after one JWKS re-fetch', function (): void {
    $keys = [nexoSsoKeypair()['jwk']];
    $down = false;
    nexoSsoRotatingProvider($keys, $down);
    $sso = app(NexoSsoService::class);

    $sso->validateIdToken(nexoSsoIdToken(), nexoSsoNonce()); // caches the old JWKS
    $keys = [nexoSsoExtraKeypair('rotated-1')['jwk']];       // Nexo ID rotates

    $claims = $sso->validateIdToken(nexoSsoRotatedIdToken(), nexoSsoNonce());

    expect($claims['sub'])->toBe('user-uuid-0001')
        ->and(nexoSsoJwksFetches())->toBe(2);
});

test('AC-JWKS-1: an id_token whose kid is not in the cached JWKS verifies after one re-fetch', function (): void {
    $keys = [nexoSsoKeypair()['jwk']];
    $down = false;
    nexoSsoRotatingProvider($keys, $down);
    $sso = app(NexoSsoService::class);

    $sso->validateIdToken(nexoSsoIdToken(), nexoSsoNonce());
    $keys = [nexoSsoKeypair()['jwk'], nexoSsoExtraKeypair('rotated-1')['jwk']];

    expect($sso->validateIdToken(nexoSsoRotatedIdToken('rotated-1'), nexoSsoNonce())['sub'])->toBe('user-uuid-0001')
        ->and(nexoSsoJwksFetches())->toBe(2);
});

test('AC-JWKS-1: the sign-in itself goes through across a rotation', function (): void {
    $keys = [nexoSsoKeypair()['jwk']];
    $down = false;
    nexoSsoRotatingProvider($keys, $down, nexoSsoRotatedIdToken());
    app(NexoSsoService::class)->validateIdToken(nexoSsoIdToken(), nexoSsoNonce()); // a cached, now stale JWKS
    $keys = [nexoSsoExtraKeypair('rotated-1')['jwk']];

    nexoSsoCallback($this)->assertRedirect(url(config('nexo-sso.home')));

    $this->assertAuthenticated();
});

test('AC-JWKS-2: while a rotation publishes both keys, a kid-less token verifies against the one that signed it', function (): void {
    $keys = [nexoSsoExtraKeypair('rotated-1')['jwk'], nexoSsoKeypair()['jwk']];
    $down = false;
    nexoSsoRotatingProvider($keys, $down);
    $sso = app(NexoSsoService::class);

    expect($sso->validateIdToken(nexoSsoIdToken(kid: null), nexoSsoNonce())['sub'])->toBe('user-uuid-0001')
        ->and($sso->validateIdToken(nexoSsoRotatedIdToken(), nexoSsoNonce())['sub'])->toBe('user-uuid-0001')
        ->and(nexoSsoJwksFetches())->toBe(1);
});

test('AC-JWKS-3: at most one re-fetch a minute — a second unknown key within the minute is just rejected', function (): void {
    $keys = [nexoSsoKeypair()['jwk']];
    $down = false;
    nexoSsoRotatingProvider($keys, $down);
    $sso = app(NexoSsoService::class);
    $sso->validateIdToken(nexoSsoIdToken(), nexoSsoNonce());

    // A forged token (a key Nexo ID never published) spends the re-fetch…
    expect(fn () => $sso->validateIdToken(nexoSsoRotatedIdToken(key: 'forged'), nexoSsoNonce()))->toThrow(NexoSsoException::class);
    expect(nexoSsoJwksFetches())->toBe(2);

    // …so a real rotation in the same minute waits for the next one.
    $keys = [nexoSsoExtraKeypair('rotated-1')['jwk']];
    expect(fn () => $sso->validateIdToken(nexoSsoRotatedIdToken(), nexoSsoNonce()))->toThrow(NexoSsoException::class);
    expect(nexoSsoJwksFetches())->toBe(2);

    $this->travel(61)->seconds();

    expect($sso->validateIdToken(nexoSsoRotatedIdToken(), nexoSsoNonce())['sub'])->toBe('user-uuid-0001')
        ->and(nexoSsoJwksFetches())->toBe(3);
});

test('AC-JWKS-4: a logout_token signed with a rotated key is accepted after one re-fetch', function (): void {
    $keys = [nexoSsoKeypair()['jwk']];
    $down = false;
    nexoSsoRotatingProvider($keys, $down);
    $sso = app(NexoSsoService::class);
    $sso->validateIdToken(nexoSsoIdToken(), nexoSsoNonce());
    $keys = [nexoSsoExtraKeypair('rotated-1')['jwk']];

    // Nexo ID signs the logout_token kid-less, like the id_token.
    $token = nexoSsoLogoutToken([], nexoSsoExtraKeypair('rotated-1')['private'], null);

    $this->post(route('nexo-sso.backchannel'), ['logout_token' => $token])->assertOk();
    expect(nexoSsoJwksFetches())->toBe(2);
});

test('AC-JWKS-5: what a fresh JWKS can\'t fix never re-fetches it (expired, wrong audience, alg=none)', function (string $case): void {
    $keys = [nexoSsoKeypair()['jwk']];
    $down = false;
    nexoSsoRotatingProvider($keys, $down);
    $sso = app(NexoSsoService::class);

    $token = match ($case) {
        'expired' => nexoSsoIdToken(['exp' => now()->subMinute()->getTimestamp(), 'iat' => now()->subMinutes(10)->getTimestamp()]),
        'audience' => nexoSsoIdToken(['aud' => 'another-client']),
        'alg none' => nexoSsoUnsignedToken(),
    };

    expect(fn () => $sso->validateIdToken($token, nexoSsoNonce()))->toThrow(NexoSsoException::class);
    expect(nexoSsoJwksFetches())->toBe(1);
})->with(['expired', 'audience', 'alg none']);

test('AC-JWKS-6: a re-fetch that fails keeps the cached JWKS (tokens of the current key keep verifying)', function (): void {
    $keys = [nexoSsoKeypair()['jwk']];
    $down = false;
    nexoSsoRotatingProvider($keys, $down);
    $sso = app(NexoSsoService::class);
    $sso->validateIdToken(nexoSsoIdToken(), nexoSsoNonce());

    $down = true;
    expect(fn () => $sso->validateIdToken(nexoSsoRotatedIdToken(), nexoSsoNonce()))->toThrow(NexoSsoException::class);

    expect($sso->validateIdToken(nexoSsoIdToken(), nexoSsoNonce())['sub'])->toBe('user-uuid-0001');
});

test('AC-JWKS-7: only the RS256 signing keys of the set verify — by kid too (a key the JWKS marks for another algorithm or for encryption never does)', function (string $case): void {
    $pair = nexoSsoExtraKeypair('other-use');
    $jwk = $pair['jwk'];
    [$jwk, $alg] = match ($case) {
        'RS512 key' => [['alg' => 'RS512'] + $jwk, 'RS512'],
        'encryption key' => [['use' => 'enc'] + $jwk, 'RS256'],
    };
    $keys = [nexoSsoKeypair()['jwk'], $jwk];
    $down = false;
    nexoSsoRotatingProvider($keys, $down);

    $token = JWT::encode(nexoSsoClaims(), $pair['private'], $alg, 'other-use');

    expect(fn () => app(NexoSsoService::class)->validateIdToken($token, nexoSsoNonce()))->toThrow(NexoSsoException::class);
    // A normal token keeps verifying against the RS256 key next to it.
    expect(app(NexoSsoService::class)->validateIdToken(nexoSsoIdToken(), nexoSsoNonce())['sub'])->toBe('user-uuid-0001');
})->with(['RS512 key', 'encryption key']);

test('AC-JWKS-7: a cached JWKS with no usable key reads as an unknown key: one re-fetch, then it verifies', function (): void {
    $keys = [];
    $down = false;
    nexoSsoRotatingProvider($keys, $down);
    $sso = app(NexoSsoService::class);

    expect(fn () => $sso->validateIdToken(nexoSsoIdToken(), nexoSsoNonce()))->toThrow(NexoSsoException::class);
    $this->travel(61)->seconds();
    $keys = [nexoSsoKeypair()['jwk']];

    expect($sso->validateIdToken(nexoSsoIdToken(), nexoSsoNonce())['sub'])->toBe('user-uuid-0001');
});
