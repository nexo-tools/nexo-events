<?php

// Shared support for the NexoSso suite: a throwaway RSA keypair, a fake OIDC
// provider (discovery/JWKS/token), id_token and logout_token signers and
// pending-flow helpers. require_once'd by each test file — functions are
// guarded for repeat inclusion. Times come from now(), so travel() works.

use App\Models\User;
use App\Services\NexoSso\NexoSsoFlows;
use App\Services\NexoSso\NexoSsoLinkConflictException;
use App\Services\NexoSso\NexoSsoService;
use App\Support\NexoChrome;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

if (! function_exists('nexoSsoKeypair')) {
    /** @return array{private: string, public: string, jwk: array<string, string>} */
    function nexoSsoKeypair(): array
    {
        static $pair = null;
        if ($pair !== null) {
            return $pair;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);
        $details = openssl_pkey_get_details($key);

        $b64url = fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        return $pair = [
            'private' => $privatePem,
            // The PEM of the PUBLIC key — used by the RS/HS confusion test, which
            // signs an HS256 token with this public material as the HMAC secret.
            'public' => $details['key'],
            'jwk' => [
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => 'nexo-test-key',
                'n' => $b64url($details['rsa']['n']),
                'e' => $b64url($details['rsa']['e']),
            ],
        ];
    }
}

if (! function_exists('nexoSsoExtraKeypair')) {
    /**
     * Another throwaway RSA keypair, one per $kid (cached): a rotated Nexo ID
     * signing key for the JWKS rotation tests.
     *
     * @return array{private: string, jwk: array<string, string>}
     */
    function nexoSsoExtraKeypair(string $kid): array
    {
        static $pairs = [];
        if (isset($pairs[$kid])) {
            return $pairs[$kid];
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);
        $details = openssl_pkey_get_details($key);
        $b64url = fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        return $pairs[$kid] = [
            'private' => $privatePem,
            'jwk' => [
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => $kid,
                'n' => $b64url($details['rsa']['n']),
                'e' => $b64url($details['rsa']['e']),
            ],
        ];
    }
}

if (! function_exists('nexoSsoEnforceCsrf')) {
    /**
     * Turns the framework's CSRF check back on for this test: it skips itself
     * while running unit tests (env "testing"), and the 419 paths are what is
     * under test here.
     */
    function nexoSsoEnforceCsrf(TestCase $test): void
    {
        app()->instance('env', 'local');
    }
}

if (! function_exists('nexoSsoAccountTakenMessage')) {
    /**
     * What a person reads when the account is already joined to another Nexo
     * ID (AC-LINK-4) — its wording, with or without a support email, is
     * pinned in NexoSsoLinkTest.
     */
    function nexoSsoAccountTakenMessage(): string
    {
        return (new NexoSsoLinkConflictException(NexoSsoLinkConflictException::ACCOUNT_TAKEN))->userMessage();
    }
}

if (! function_exists('nexoSsoDistinctBrand')) {
    /**
     * Makes the chrome brand and APP_NAME differ, so a screen that names the
     * tool can be checked to use the brand (NexoSsoApp::name()). Returns the
     * name the screens must show; APP_NAME is 'NexoPlainAppName'.
     */
    function nexoSsoDistinctBrand(): string
    {
        config(['app.name' => 'NexoPlainAppName']);
        if (class_exists(NexoChrome::class) && NexoChrome::current() !== null) {
            config(['nexo-ecosystem.tools.'.NexoChrome::current().'.name' => 'Nexo Brand Name']);

            return 'Nexo Brand Name';
        }

        return 'NexoPlainAppName';
    }
}

if (! function_exists('nexoSsoLinkFlowSession')) {
    /**
     * Session data for a pending «Link my account» flow of $user answered by
     * nexoSsoState(), as NexoSsoFlows stores it. Merge into withSession().
     *
     * @return array<string, mixed>
     */
    function nexoSsoLinkFlowSession(User $user, ?string $return = '/nexo-sso-tests/panel'): array
    {
        return ['nexo_sso.flows' => [hash('sha256', nexoSsoState()) => [
            'mode' => NexoSsoFlows::LINK,
            'verifier' => str_repeat('v', 64),
            'nonce' => nexoSsoNonce(),
            'return' => $return,
            'created_at' => now()->getTimestamp(),
            'user' => (string) $user->getAuthIdentifier(),
        ]]];
    }
}

if (! function_exists('nexoSsoNonce')) {
    /** The nonce the flow helpers plant and the default id_token echoes. */
    function nexoSsoNonce(): string
    {
        return str_repeat('n', 40);
    }
}

if (! function_exists('nexoSsoState')) {
    /** The state the flow helpers plant and the callback helpers send back. */
    function nexoSsoState(): string
    {
        return str_repeat('s', 40);
    }
}

if (! function_exists('nexoSsoClaims')) {
    /**
     * The default id_token claim set; override any claim (null removes it).
     *
     * @return array<string, mixed>
     */
    function nexoSsoClaims(array $overrides = []): array
    {
        $now = now()->getTimestamp();

        $claims = array_merge([
            'iss' => config('nexo-sso.issuer'),
            'aud' => config('nexo-sso.client_id'),
            'sub' => 'user-uuid-0001',
            'sid' => 'nexo-sid-0001',
            'exp' => $now + 300,
            'iat' => $now,
            // Echoes the nonce the flow helpers plant, so the default id_token
            // passes the nonce check. (AC-NONCE-1)
            'nonce' => nexoSsoNonce(),
            'email' => 'user@example.com',
            'email_verified' => true,
            'name' => 'Test User',
        ], $overrides);

        return array_filter($claims, fn ($value) => $value !== null);
    }
}

if (! function_exists('nexoSsoIdToken')) {
    /**
     * Signed id_token with sane defaults; override any claim (null removes it).
     * Pass $kid=null to emit a token with NO `kid` header — the real Nexo ID
     * production path (its bridge signs kid-less), which the kid-less single-key
     * branch of NexoSsoService::validateIdToken must handle.
     */
    function nexoSsoIdToken(array $overrides = [], ?string $signWithPem = null, ?string $kid = 'nexo-test-key'): string
    {
        return JWT::encode(nexoSsoClaims($overrides), $signWithPem ?? nexoSsoKeypair()['private'], 'RS256', $kid);
    }
}

if (! function_exists('nexoSsoLogoutToken')) {
    /**
     * Signed back-channel logout_token (OIDC Back-Channel Logout 1.0) with the
     * claims the contract fixes: iss, aud, iat, exp=iat+120, a fresh jti, sub,
     * sid and the back-channel event — no nonce. Override any claim (null
     * removes it); same key/kid as the id_token.
     */
    function nexoSsoLogoutToken(array $overrides = [], ?string $signWithPem = null, ?string $kid = 'nexo-test-key'): string
    {
        $now = now()->getTimestamp();

        $claims = array_filter(array_merge([
            'iss' => config('nexo-sso.issuer'),
            'aud' => config('nexo-sso.client_id'),
            'iat' => $now,
            'exp' => $now + 120,
            'jti' => (string) Str::uuid(),
            'sub' => 'user-uuid-0001',
            'sid' => 'nexo-sid-0001',
            'events' => [NexoSsoService::BACKCHANNEL_EVENT => new stdClass],
        ], $overrides), fn ($value) => $value !== null);

        return JWT::encode($claims, $signWithPem ?? nexoSsoKeypair()['private'], 'RS256', $kid, ['typ' => 'logout+jwt']);
    }
}

if (! function_exists('nexoSsoUnsignedToken')) {
    /**
     * A forged `alg=none` id_token (header alg=none, empty signature) — the
     * classic algorithm-stripping attack. Must be rejected: no session may be
     * created from an unsigned token.
     */
    function nexoSsoUnsignedToken(array $overrides = []): string
    {
        $b64url = fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $header = $b64url((string) json_encode(['typ' => 'JWT', 'alg' => 'none']));
        $payload = $b64url((string) json_encode(nexoSsoClaims($overrides)));

        return $header.'.'.$payload.'.';
    }
}

if (! function_exists('nexoSsoFakeProvider')) {
    /**
     * Fakes discovery + JWKS + token endpoints. Pass the token response body
     * and, to simulate a failing exchange, a non-2xx $tokenStatus (e.g. 400/500)
     * so the service's ->throw() surfaces a Throwable. $discoveryOverrides
     * merges into (and can drop, via a null value) discovery keys — e.g.
     * ['end_session_endpoint' => null] models a provider that advertises no
     * RP-initiated logout.
     */
    function nexoSsoFakeProvider(?array $tokenResponse = null, array $discoveryOverrides = [], int $tokenStatus = 200): void
    {
        $issuer = config('nexo-sso.issuer');
        $tokenResponse ??= ['access_token' => 'fake-access-token', 'token_type' => 'Bearer', 'id_token' => nexoSsoIdToken()];

        $discovery = array_filter(array_merge([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'userinfo_endpoint' => $issuer.'/oauth/userinfo',
            'jwks_uri' => $issuer.'/oauth/jwks',
            'end_session_endpoint' => $issuer.'/oauth/logout',
        ], $discoveryOverrides), fn ($value): bool => $value !== null);

        Http::fake([
            $issuer.'/.well-known/openid-configuration' => Http::response($discovery),
            $issuer.'/oauth/jwks' => Http::response(['keys' => [nexoSsoKeypair()['jwk']]]),
            $issuer.'/oauth/token' => Http::response($tokenResponse, $tokenStatus),
        ]);
    }
}

if (! function_exists('nexoSsoFlowSession')) {
    /**
     * Session data for a pending guest flow (interactive or silent) answered by
     * nexoSsoState(), as NexoSsoFlows stores it. Merge into withSession().
     *
     * @return array<string, mixed>
     */
    function nexoSsoFlowSession(string $mode = NexoSsoFlows::INTERACTIVE, ?string $return = null): array
    {
        return ['nexo_sso.flows' => [hash('sha256', nexoSsoState()) => [
            'mode' => $mode,
            'verifier' => str_repeat('v', 64),
            'nonce' => nexoSsoNonce(),
            'return' => $return,
            'created_at' => now()->getTimestamp(),
        ]]];
    }
}

if (! function_exists('nexoSsoStoredFlow')) {
    /**
     * The guest flow the app stored for $state (from an authorize redirect's
     * query), or null.
     *
     * @return array<string, mixed>|null
     */
    function nexoSsoStoredFlow(string $state): ?array
    {
        return session('nexo_sso.flows', [])[hash('sha256', $state)] ?? null;
    }
}

if (! function_exists('nexoSsoAuthorizeUrl')) {
    /**
     * Where a response sends the browser on to Nexo ID: a redirect's Location,
     * or the meta refresh of the page `enter` answers a guest with (v2.3,
     * AC-ENTER-7) — '' when it does neither.
     */
    function nexoSsoAuthorizeUrl(TestResponse $response): string
    {
        $location = $response->headers->get('Location');
        if ($location !== null) {
            return (string) $location;
        }

        preg_match('/<meta http-equiv="refresh" content="0;url=([^"]*)">/', (string) $response->getContent(), $m);

        return html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5);
    }
}

if (! function_exists('nexoSsoAuthorizeQuery')) {
    /**
     * The query of the authorize request a response starts (nexoSsoAuthorizeUrl).
     *
     * @return array<string, string>
     */
    function nexoSsoAuthorizeQuery(TestResponse $response): array
    {
        parse_str((string) parse_url(nexoSsoAuthorizeUrl($response), PHP_URL_QUERY), $query);

        return $query;
    }
}

if (! function_exists('nexoSsoRecheckFlow')) {
    /** A pending re-validation flow of $user answered by $state, as NexoSsoFlows stores it. */
    function nexoSsoRecheckFlow(User $user, string $return = '/', ?string $state = null): void
    {
        Cache::put('nexo-sso.flow.'.hash('sha256', $state ?? nexoSsoState()), [
            'mode' => NexoSsoFlows::RECHECK,
            'verifier' => str_repeat('v', 64),
            'nonce' => nexoSsoNonce(),
            'return' => $return,
            'created_at' => now()->getTimestamp(),
            'user' => (string) $user->getAuthIdentifier(),
        ], 600);
    }
}

if (! function_exists('nexoSsoCallback')) {
    /** Drives the callback for a pending flow of $mode, as if the provider redirected back with a code. */
    function nexoSsoCallback(TestCase $test, string $code = 'auth-code', string $mode = NexoSsoFlows::INTERACTIVE, ?string $return = null): TestResponse
    {
        return $test
            ->withSession(nexoSsoFlowSession($mode, $return))
            ->get(route('nexo-sso.callback', ['code' => $code, 'state' => nexoSsoState()]));
    }
}

if (! function_exists('nexoSsoErrorCallback')) {
    /** Drives the callback as if the provider answered a pending flow with an OIDC error (no code). */
    function nexoSsoErrorCallback(TestCase $test, string $error = 'access_denied', string $mode = NexoSsoFlows::INTERACTIVE, ?string $return = null): TestResponse
    {
        return $test
            ->withSession(nexoSsoFlowSession($mode, $return))
            ->get(route('nexo-sso.callback', ['error' => $error, 'state' => nexoSsoState()]));
    }
}

if (! function_exists('nexoSsoSignedIn')) {
    /**
     * A user signed in through Nexo ID with v2 session keys. $checkedAgo: seconds
     * since the last re-validation (null = no checked_at at all).
     *
     * @return array<string, mixed> session data for withSession()
     */
    function nexoSsoSignedIn(TestCase $test, User $user, ?int $checkedAgo = 0, array $extra = []): array
    {
        $user->forceFill(['nexo_id_sub' => 'user-uuid-0001'])->save();
        $test->actingAs($user);
        $now = now()->getTimestamp();

        return array_filter(array_merge([
            'nexo_sso.sub' => 'user-uuid-0001',
            'nexo_sso.sid' => 'nexo-sid-0001',
            'nexo_sso.id_token' => 'previous-id-token',
            'nexo_sso.authenticated_at' => $now - 3600,
            'nexo_sso.checked_at' => $checkedAgo === null ? null : $now - $checkedAgo,
        ], $extra), fn ($value) => $value !== null);
    }
}
