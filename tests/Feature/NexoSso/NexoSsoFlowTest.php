<?php

use App\Services\NexoSso\NexoSsoFlows;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('AC-FLOW-1: the authorize redirect carries code+PKCE(S256)+state and the exact callback', function (): void {
    nexoSsoFakeProvider();

    $response = $this->get(route('nexo-sso.redirect'));

    $response->assertRedirect();
    $query = nexoSsoAuthorizeQuery($response);
    $flow = nexoSsoStoredFlow($query['state']);

    expect($flow)->not->toBeNull()
        ->and($flow['mode'])->toBe(NexoSsoFlows::INTERACTIVE)
        ->and($query['response_type'])->toBe('code')
        ->and($query['client_id'])->toBe(config('nexo-sso.client_id'))
        ->and($query['redirect_uri'])->toBe(route('nexo-sso.callback'))
        ->and($query['scope'])->toBe('openid profile email')
        ->and($query['nonce'])->toBe($flow['nonce'])
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe(
            rtrim(strtr(base64_encode(hash('sha256', $flow['verifier'], true)), '+/', '-_'), '=')
        )
        ->and($query)->not->toHaveKey('prompt');

    // The state and nonce are fresh random values, not fixed constants. (AC-NONCE-1)
    expect(strlen($query['state']))->toBeGreaterThanOrEqual(32)
        ->and(strlen((string) $query['nonce']))->toBeGreaterThanOrEqual(32);
});

test('AC-FLOW-1: each authorize request keeps its own state, so two tabs never overwrite each other', function (): void {
    nexoSsoFakeProvider();

    $first = nexoSsoAuthorizeQuery($this->get(route('nexo-sso.redirect')));
    $second = nexoSsoAuthorizeQuery($this->get(route('nexo-sso.redirect')));

    expect($first['state'])->not->toBe($second['state'])
        ->and(nexoSsoStoredFlow($first['state']))->not->toBeNull()
        ->and(nexoSsoStoredFlow($second['state']))->not->toBeNull();
});

test('AC-NONCE-1: an id_token whose nonce does not match the flow is rejected', function (): void {
    nexoSsoFakeProvider([
        'access_token' => 'fake', 'token_type' => 'Bearer',
        'id_token' => nexoSsoIdToken(['nonce' => 'a-different-nonce']),
    ]);

    nexoSsoCallback($this)->assertRedirect(route('login'))->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();
});

test('AC-NONCE-1: an id_token carrying no nonce claim is rejected', function (): void {
    nexoSsoFakeProvider([
        'access_token' => 'fake', 'token_type' => 'Bearer',
        'id_token' => nexoSsoIdToken(['nonce' => null]), // null override drops the claim
    ]);

    nexoSsoCallback($this)->assertRedirect(route('login'))->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();
});

test('AC-NONCE-1: a matching nonce completes the login', function (): void {
    nexoSsoFakeProvider(); // default id_token echoes the flow nonce

    nexoSsoCallback($this)->assertRedirect();
    $this->assertAuthenticated();
});

test('AC-FLOW-2: a mismatched or missing state is rejected before any provider call', function (): void {
    Http::fake();

    // A state this browser never sent.
    $this->withSession(nexoSsoFlowSession())
        ->get(route('nexo-sso.callback', ['code' => 'auth-code', 'state' => 'evil-state']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();

    // No state at all.
    $this->get(route('nexo-sso.callback', ['code' => 'auth-code']))
        ->assertRedirect(route('login'));
    $this->assertGuest();

    Http::assertNothingSent();
});

test('AC-FLOW-2: a state is single use — replaying the callback is rejected', function (): void {
    nexoSsoFakeProvider();

    nexoSsoCallback($this)->assertRedirect();
    $this->assertAuthenticated();

    auth()->logout();
    $this->get(route('nexo-sso.callback', ['code' => 'auth-code', 'state' => nexoSsoState()]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();
});

test('AC-FLOW-2: a flow older than 10 minutes is expired — its callback is rejected before any provider call', function (): void {
    Http::fake();
    $session = nexoSsoFlowSession();

    $this->travel(601)->seconds();

    $this->withSession($session)
        ->get(route('nexo-sso.callback', ['code' => 'auth-code', 'state' => nexoSsoState()]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();
    Http::assertNothingSent();
});

test('AC-FLOW-2: the user denying consent (provider error, no code) redirects to login with a safe error and no session', function (): void {
    Http::fake();

    nexoSsoErrorCallback($this, 'access_denied')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();

    // Denial short-circuits before any token exchange.
    Http::assertNothingSent();
});

test('AC-FLOW-3: a tampered id_token signature aborts login with a safe error', function (): void {
    $foreignKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($foreignKey, $foreignPem);
    nexoSsoFakeProvider([
        'access_token' => 'fake', 'token_type' => 'Bearer',
        'id_token' => nexoSsoIdToken([], $foreignPem),
    ]);

    nexoSsoCallback($this)->assertRedirect(route('login'))->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();
});

test('AC-FLOW-3: an expired id_token aborts login with a safe error', function (): void {
    nexoSsoFakeProvider([
        'access_token' => 'fake', 'token_type' => 'Bearer',
        'id_token' => nexoSsoIdToken(['exp' => now()->getTimestamp() - 120]),
    ]);

    nexoSsoCallback($this)->assertRedirect(route('login'))->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();
});

// Split into two single-stub tests: Http::fake is first-match-wins, so registering
// two fake providers in one test would leave the second stub (and its branch) dead —
// both callbacks would abort on the first stub's failure, never reaching the second
// check. One provider per test guarantees each branch (aud, then iss) is exercised.
test('AC-FLOW-3: an id_token for another audience aborts login', function (): void {
    nexoSsoFakeProvider([
        'access_token' => 'fake', 'token_type' => 'Bearer',
        'id_token' => nexoSsoIdToken(['aud' => 'another-client']),
    ]);
    nexoSsoCallback($this)->assertRedirect(route('login'))->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();
});

test('AC-FLOW-3: an id_token from another issuer aborts login', function (): void {
    nexoSsoFakeProvider([
        'access_token' => 'fake', 'token_type' => 'Bearer',
        'id_token' => nexoSsoIdToken(['iss' => 'https://evil.test']),
    ]);
    nexoSsoCallback($this)->assertRedirect(route('login'))->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();
});
