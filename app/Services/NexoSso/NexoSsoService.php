<?php

declare(strict_types=1);

namespace App\Services\NexoSso;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;
use UnexpectedValueException;

final class NexoSsoService
{
    /** The `events` member every back-channel logout_token must carry. */
    public const BACKCHANNEL_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    /** Clock tolerance (seconds) for a logout_token's iat. (§2.3: ±5 min) */
    private const LOGOUT_TOKEN_IAT_WINDOW = 300;

    /** How long a seen logout_token jti is remembered (replay guard). */
    private const LOGOUT_TOKEN_JTI_TTL = 600;

    /** At most one JWKS re-fetch per this many seconds (key rotation, §0.1 item 3). */
    private const JWKS_REFRESH_EVERY = 60;

    /** A kid-less token is tried against at most this many keys of the JWKS. */
    private const KIDLESS_MAX_KEYS = 5;

    /**
     * Discovery document of the configured issuer, cached. Endpoints are never
     * hard-coded: a self-hosted instance may lay them out differently. (AC-CFG-2)
     *
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        return Cache::remember(
            'nexo-sso.discovery',
            config('nexo-sso.discovery_ttl'),
            fn (): array => $this->http()
                ->get(config('nexo-sso.issuer').'/.well-known/openid-configuration')
                ->throw()
                ->json()
        );
    }

    /**
     * Origin (scheme://host[:port]) of the configured issuer, or null. The
     * browser hands forms and their redirects to it (login, central logout), so
     * the tool's CSP `form-action` must list it when SSO is on. (§3)
     */
    public static function issuerOrigin(): ?string
    {
        $parts = parse_url((string) config('nexo-sso.issuer', ''));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Is Nexo ID answering? Asked before a re-validation bounce so a provider
     * outage never strands a signed-in user on an error page (fail-open, R8). A
     * good answer is cached for probe_ttl seconds; a failure for probe_retry
     * seconds, during which nobody is bounced.
     */
    public function isReachable(): bool
    {
        $known = Cache::get('nexo-sso.probe');
        if ($known === 'up' || $known === 'down') {
            return $known === 'up';
        }

        $timeout = (int) config('nexo-sso.probe_timeout', 2);

        try {
            $reachable = Http::timeout($timeout)->connectTimeout($timeout)
                ->get(config('nexo-sso.issuer').'/.well-known/openid-configuration')
                ->successful();
        } catch (Throwable) {
            $reachable = false;
        }

        Cache::put(
            'nexo-sso.probe',
            $reachable ? 'up' : 'down',
            $reachable ? (int) config('nexo-sso.probe_ttl', 60) : (int) config('nexo-sso.probe_retry', 300),
        );

        return $reachable;
    }

    /**
     * Authorization request: code + PKCE(S256) + state + nonce. (AC-FLOW-1, AC-NONCE-1)
     *
     * $prompt: OIDC `prompt` value — `none` performs a silent attempt (the
     * provider returns the code with no UI when a session exists, or bounces
     * straight back with `error=login_required` when it doesn't). (AC-SILENT-1)
     */
    public function buildAuthorizeUrl(string $state, string $codeChallenge, string $nonce, ?string $prompt = null): string
    {
        return $this->discovery()['authorization_endpoint'].'?'.http_build_query(array_filter([
            'client_id' => config('nexo-sso.client_id'),
            'redirect_uri' => route('nexo-sso.callback'),
            'response_type' => 'code',
            'scope' => config('nexo-sso.scopes'),
            'state' => $state,
            // Replay/injection guard: the provider echoes this back as the id_token
            // `nonce` claim; the callback rejects a token whose nonce doesn't match
            // the one this browser sent. (AC-NONCE-1)
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'prompt' => $prompt,
        ], fn ($value): bool => $value !== null));
    }

    /**
     * Where Nexo ID sends the browser after a central logout: the configured
     * URI, or this tool's own signed-out screen at <APP_URL>/auth/nexo/signed-out.
     */
    public function postLogoutRedirectUri(): string
    {
        $configured = (string) config('nexo-sso.post_logout_redirect_uri', '');

        return $configured !== '' ? $configured : rtrim((string) config('app.url'), '/').'/auth/nexo/signed-out';
    }

    /**
     * RP-initiated (front-channel) logout URL, per OIDC session management. Null
     * when the provider advertises no `end_session_endpoint` (discovery) — callers
     * then fall back to a local-only logout. The URL carries:
     *   - id_token_hint: the raw id_token from login, so the provider can identify
     *     the session AND the client whose registered URIs bound the redirect.
     *   - post_logout_redirect_uri: where the provider sends the browser back.
     *
     * Without a hint (the tool session already expired, or a stale form) it
     * carries `client_id` instead: Nexo ID then asks «¿Cerrar sesión en todo
     * Nexo?» before ending anything (a bare link can't sign anyone out), and
     * honours post_logout_redirect_uri only if it is registered for that client.
     *
     * Contract (enforced provider-side, see Nexo ID end_session controller): the
     * provider MUST validate post_logout_redirect_uri against the URIs registered
     * for the id_token_hint's client (or for client_id when there's no hint) and
     * MUST NOT open-redirect. An unregistered URI lands on the provider's own
     * "signed out" page instead.
     */
    public function buildEndSessionUrl(?string $idTokenHint): ?string
    {
        $endpoint = $this->discovery()['end_session_endpoint'] ?? null;
        if (! is_string($endpoint) || $endpoint === '') {
            return null;
        }

        $hint = is_string($idTokenHint) && $idTokenHint !== '' ? $idTokenHint : null;

        $params = array_filter([
            'id_token_hint' => $hint,
            'client_id' => $hint === null ? (string) config('nexo-sso.client_id') : null,
            'post_logout_redirect_uri' => $this->postLogoutRedirectUri(),
        ], fn ($value): bool => is_string($value) && $value !== '');

        return $endpoint.'?'.http_build_query($params);
    }

    /**
     * Exchange the authorization code (server-side, with the PKCE verifier).
     *
     * @return array<string, mixed>
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        return $this->http()
            ->asForm()
            ->post($this->discovery()['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'client_id' => config('nexo-sso.client_id'),
                'redirect_uri' => route('nexo-sso.callback'),
                'code' => $code,
                'code_verifier' => $codeVerifier,
            ])
            ->throw()
            ->json();
    }

    /**
     * Validate the id_token: RS256 signature against the JWKS, exp (enforced by
     * JWT::decode), iss, aud and nonce. Any failure throws — no session gets
     * created from an unverified token. (AC-FLOW-3, AC-NONCE-1)
     *
     * $expectedNonce is the nonce this browser sent to /authorize (kept with the
     * flow). A missing/empty expected nonce, a token with no nonce claim, or a
     * mismatch all reject: an id_token minted for a different authorize request
     * cannot be replayed into this callback.
     *
     * @return array<string, mixed> the verified claims
     */
    public function validateIdToken(string $idToken, string $expectedNonce): array
    {
        if ($idToken === '') {
            throw new NexoSsoException('Missing id_token in token response.');
        }

        $claims = $this->verifiedClaims($idToken, 0, 'id_token');
        $this->assertIssuerAndAudience($claims, 'id_token');

        // Bind the token to this browser's authorize request. (AC-NONCE-1)
        $nonce = (string) ($claims['nonce'] ?? '');
        if ($expectedNonce === '' || $nonce === '' || ! hash_equals($expectedNonce, $nonce)) {
            throw new NexoSsoException('id_token nonce mismatch.');
        }

        return $claims;
    }

    /**
     * Validate a back-channel logout_token (OIDC Back-Channel Logout 1.0 §2.6):
     * RS256 signature against the same JWKS as the id_token (by kid), iss, aud,
     * iat within ±5 min, exp not passed, the back-channel `events` member, NO
     * nonce (an id_token can't be replayed as a logout), a sid or a sub, and a
     * jti never seen before (remembered 10 min). Any failure throws. (AC-BCL-C)
     *
     * @return array<string, mixed> the verified claims
     */
    public function validateLogoutToken(string $logoutToken): array
    {
        if ($logoutToken === '' || substr_count($logoutToken, '.') !== 2) {
            throw new NexoSsoException('Malformed logout_token.');
        }

        $claims = $this->verifiedClaims($logoutToken, self::LOGOUT_TOKEN_IAT_WINDOW, 'logout_token');
        $this->assertIssuerAndAudience($claims, 'logout_token');

        $now = now()->getTimestamp();

        if (! is_numeric($claims['iat'] ?? null) || abs($now - (int) $claims['iat']) > self::LOGOUT_TOKEN_IAT_WINDOW) {
            throw new NexoSsoException('logout_token iat outside the accepted window.');
        }

        // Strict: the decode above tolerates clock skew on iat, not on exp.
        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] <= $now) {
            throw new NexoSsoException('logout_token expired.');
        }

        $events = $claims['events'] ?? null;
        if (! is_array($events) || ! array_key_exists(self::BACKCHANNEL_EVENT, $events) || ! is_array($events[self::BACKCHANNEL_EVENT])) {
            throw new NexoSsoException('logout_token without the back-channel logout event.');
        }

        if (array_key_exists('nonce', $claims)) {
            throw new NexoSsoException('logout_token must not carry a nonce.');
        }

        // A sid or a sub, and each one that is present a non-empty string: a
        // malformed sid must not degrade into a by-sub revocation (every
        // session of the person instead of one device).
        $sid = $claims['sid'] ?? null;
        $sub = $claims['sub'] ?? null;
        if ($sid === null && $sub === null) {
            throw new NexoSsoException('logout_token without sid or sub.');
        }

        foreach (['sid' => $sid, 'sub' => $sub] as $claim => $value) {
            if ($value !== null && (! is_string($value) || $value === '')) {
                throw new NexoSsoException('logout_token with a malformed '.$claim.'.');
            }
        }

        $jti = $claims['jti'] ?? null;
        if (! is_string($jti) || $jti === '') {
            throw new NexoSsoException('logout_token without jti.');
        }

        // Last, so a token that fails any other check doesn't burn its jti.
        if (! Cache::add($this->logoutJtiKey($jti), true, self::LOGOUT_TOKEN_JTI_TTL)) {
            throw new NexoSsoException('logout_token replayed (jti already seen).');
        }

        return $claims;
    }

    /**
     * Forgets the jti of a validated logout_token whose revocation could not be
     * stored, so the provider's retry of that same token is not refused as a
     * replay.
     *
     * @param  array<string, mixed>  $claims  as returned by validateLogoutToken()
     */
    public function releaseLogoutToken(array $claims): void
    {
        $jti = $claims['jti'] ?? null;
        if (is_string($jti) && $jti !== '') {
            Cache::forget($this->logoutJtiKey($jti));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function userinfo(string $accessToken): array
    {
        return $this->http()
            ->withToken($accessToken)
            ->get($this->discovery()['userinfo_endpoint'])
            ->throw()
            ->json();
    }

    /**
     * Signature + time checks against the JWKS. The algorithm is pinned to RS256
     * by the key (firebase/php-jwt binds header `alg` to the key's algorithm):
     * alg=none and RS/HS confusion both fail here. "Now" is the app clock, so
     * tests can travel in time.
     *
     * Key rotation: when the signature doesn't verify against the cached JWKS,
     * or the token's kid isn't in it, the JWKS is fetched again and the token
     * retried once. At most one re-fetch per minute for the whole app (a cache
     * lock), so a stream of forged tokens can't turn into a stream of requests
     * to Nexo ID. A failed re-fetch keeps the cached JWKS. (AC-JWKS)
     *
     * @return array<string, mixed>
     */
    private function verifiedClaims(string $jwt, int $leeway, string $what): array
    {
        $previousTimestamp = JWT::$timestamp;
        $previousLeeway = JWT::$leeway;

        try {
            JWT::$timestamp = now()->getTimestamp();
            JWT::$leeway = $leeway;

            try {
                $decoded = $this->decode($jwt, $this->jwks());
            } catch (UnexpectedValueException $e) {
                if (! $this->isUnknownKey($e) || ! $this->mayRefreshJwks()) {
                    throw $e;
                }

                $decoded = $this->decode($jwt, $this->fetchJwks());
            }
        } catch (Throwable $e) {
            throw new NexoSsoException($what.' verification failed: '.$e->getMessage(), previous: $e);
        } finally {
            JWT::$timestamp = $previousTimestamp;
            JWT::$leeway = $previousLeeway;
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode((string) json_encode($decoded), true);

        return $claims;
    }

    /**
     * One decode attempt against a JWKS — only against its RS256 signing keys,
     * whatever else the set publishes: an HS/ES/PS key (or one marked for
     * encryption) never verifies a Nexo ID token. With a kid, the keyset
     * lookup. Without one (Nexo ID signs kid-less, the id_token and the
     * logout_token alike), those keys in order: a single key is unambiguous,
     * and while a rotation publishes the old and the new key side by side the
     * token verifies against the one that signed it. No usable key at all
     * reads as an unknown key, so the JWKS gets re-fetched.
     *
     * @param  array<string, mixed>  $jwks
     */
    private function decode(string $jwt, array $jwks): stdClass
    {
        $header = json_decode(JWT::urlsafeB64Decode(explode('.', $jwt)[0]), true);

        $keys = array_values(array_filter(
            is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [],
            fn ($jwk): bool => is_array($jwk)
                && ($jwk['kty'] ?? null) === 'RSA'
                && ($jwk['alg'] ?? 'RS256') === 'RS256'
                && ($jwk['use'] ?? 'sig') === 'sig',
        ));

        if (is_array($header) && ! empty($header['kid'])) {
            if ($keys === []) {
                throw new UnexpectedValueException('"kid" invalid, the JWKS has no RS256 signing key');
            }

            return JWT::decode($jwt, JWK::parseKeySet(['keys' => $keys], 'RS256'));
        }

        $miss = new UnexpectedValueException('"kid" empty and no RS256 key in the JWKS verifies the token');
        foreach (array_slice($keys, 0, self::KIDLESS_MAX_KEYS) as $jwk) {
            try {
                return JWT::decode($jwt, JWK::parseKey($jwk, 'RS256'));
            } catch (SignatureInvalidException $e) {
                $miss = $e; // not this key: try the next one
            }
        }

        throw $miss;
    }

    /** A signature that doesn't verify, or a kid the JWKS doesn't have: maybe a rotated key. */
    private function isUnknownKey(UnexpectedValueException $e): bool
    {
        return $e instanceof SignatureInvalidException || str_starts_with($e->getMessage(), '"kid"');
    }

    /** True at most once per JWKS_REFRESH_EVERY seconds across the app (cache lock, never released). */
    private function mayRefreshJwks(): bool
    {
        try {
            return Cache::lock('nexo-sso.jwks-refresh', self::JWKS_REFRESH_EVERY)->get();
        } catch (Throwable) {
            // A cache store without locks: the same once-a-minute rule with add().
            return Cache::add('nexo-sso.jwks-refresh-at', true, self::JWKS_REFRESH_EVERY);
        }
    }

    /** @param  array<string, mixed>  $claims */
    private function assertIssuerAndAudience(array $claims, string $what): void
    {
        if (($claims['iss'] ?? null) !== config('nexo-sso.issuer')) {
            throw new NexoSsoException($what.' issuer mismatch.');
        }

        $audience = (array) ($claims['aud'] ?? []);
        if (! in_array(config('nexo-sso.client_id'), $audience, true)) {
            throw new NexoSsoException($what.' audience mismatch.');
        }
    }

    /** @return array<string, mixed> */
    private function jwks(): array
    {
        $cached = Cache::get('nexo-sso.jwks');

        return is_array($cached) ? $cached : $this->fetchJwks();
    }

    /**
     * The provider's JWKS, fetched now and cached for jwks_ttl. Throws (and
     * leaves the cached copy alone) when the provider doesn't answer with one.
     *
     * @return array<string, mixed>
     */
    private function fetchJwks(): array
    {
        $jwks = $this->http()
            ->get($this->discovery()['jwks_uri'])
            ->throw()
            ->json();

        if (! is_array($jwks) || ! is_array($jwks['keys'] ?? null)) {
            throw new NexoSsoException('The provider answered without a JWKS.');
        }

        Cache::put('nexo-sso.jwks', $jwks, (int) config('nexo-sso.jwks_ttl'));

        return $jwks;
    }

    private function logoutJtiKey(string $jti): string
    {
        return 'nexo-sso.logout-jti.'.hash('sha256', $jti);
    }

    private function http(): PendingRequest
    {
        $timeout = config('nexo-sso.timeout');

        return Http::timeout($timeout)->connectTimeout($timeout);
    }
}
