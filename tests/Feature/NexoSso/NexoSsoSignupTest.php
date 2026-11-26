<?php

// «Crear cuenta» that comes back to the tool (v2.2): GET
// /auth/nexo/enter?to=/path&signup=1. Same `to` rules and the same signed-in
// shortcut as enter; for anyone else the interactive authorize also carries
// prompt=create, so Nexo ID opens its sign-up and, once the email is verified,
// answers the authorize with a code — the person lands in this tool, signed
// in, on `to`. Any other value of `signup` is ignored. (AC-SIGNUP)

use App\Models\User;
use App\Services\NexoSso\NexoSsoFlows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

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
 * A provider whose token endpoint answers with a brand-new Nexo ID account,
 * echoing the nonce of the authorize the test followed (set $nonce after it).
 */
function nexoSsoSignupProvider(?string &$nonce): void
{
    $issuer = config('nexo-sso.issuer');

    Http::fake([
        $issuer.'/.well-known/openid-configuration' => Http::response([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'jwks_uri' => $issuer.'/oauth/jwks',
            'end_session_endpoint' => $issuer.'/oauth/logout',
        ]),
        $issuer.'/oauth/jwks' => Http::response(['keys' => [nexoSsoKeypair()['jwk']]]),
        $issuer.'/oauth/token' => function () use (&$nonce) {
            return Http::response(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => nexoSsoIdToken([
                'sub' => 'signed-up-sub',
                'sid' => 'signed-up-sid',
                'nonce' => $nonce,
                'email' => 'just-signed-up@example.com',
                'email_verified' => true,
                'name' => 'New Person',
            ])]);
        },
    ]);
}

/** Follows «Crear cuenta» to the provider and back with a code, as a browser would. */
function nexoSsoSignUpAndReturn(TestCase $test, string $to): TestResponse
{
    $nonce = null;
    nexoSsoSignupProvider($nonce);

    $query = nexoSsoAuthorizeQuery($test->get(route('nexo-sso.enter', ['to' => $to, 'signup' => '1'])));
    expect($query['prompt'] ?? null)->toBe('create');
    $nonce = $query['nonce'];

    return $test->get(route('nexo-sso.callback', ['code' => 'signup-code', 'state' => $query['state']]));
}

test('AC-SIGNUP-1: a visitor goes to authorize with prompt=create, and the flow remembers `to`', function (): void {
    nexoSsoFakeProvider();

    $response = $this->get(route('nexo-sso.enter', ['to' => '/some/page?tab=2', 'signup' => '1']));

    expect((string) $response->headers->get('Location'))->toStartWith('https://nexoid.test/oauth/authorize?');
    $query = nexoSsoAuthorizeQuery($response);
    expect($query['prompt'] ?? null)->toBe('create')
        ->and($query['client_id'])->toBe('11111111-2222-3333-4444-555555555555')
        ->and($query['redirect_uri'])->toBe(route('nexo-sso.callback'));

    $flow = nexoSsoStoredFlow($query['state']);
    expect($flow['mode'])->toBe(NexoSsoFlows::INTERACTIVE)
        ->and($flow['return'])->toBe('/some/page?tab=2');
});

test('AC-SIGNUP-1: back from the sign-up, the new person has an account here, is signed in, and lands on `to`', function (string $mode): void {
    config(['nexo-sso.auth_mode' => $mode]);

    nexoSsoSignUpAndReturn($this, '/nexo-sso-tests/panel')->assertRedirect(url('/nexo-sso-tests/panel'));

    $user = User::query()->where('email', 'just-signed-up@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->nexo_id_sub)->toBe('signed-up-sub')
        ->and(session('nexo_sso.sub'))->toBe('signed-up-sub')
        ->and(session('nexo_sso.sid'))->toBe('signed-up-sid');
})->with(['sso', 'local']);

test('AC-SIGNUP-1: the sign-up flow waits an hour (sign-up + verification mail), a plain sign-in still 10 minutes', function (): void {
    config(['nexo-sso.auth_mode' => 'sso']);
    $nonce = null;
    nexoSsoSignupProvider($nonce);

    $signup = nexoSsoAuthorizeQuery($this->get(route('nexo-sso.enter', ['to' => '/nexo-sso-tests/panel', 'signup' => '1'])));
    $plain = nexoSsoAuthorizeQuery($this->get(route('nexo-sso.enter', ['to' => '/nexo-sso-tests/panel'])));

    $this->travel(30)->minutes(); // a sign-in would have expired by now

    $nonce = $plain['nonce']; // a valid answer: only its age can refuse it
    $this->get(route('nexo-sso.callback', ['code' => 'c', 'state' => $plain['state']]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();

    $nonce = $signup['nonce'];
    $this->get(route('nexo-sso.callback', ['code' => 'signup-code', 'state' => $signup['state']]))
        ->assertRedirect(url('/nexo-sso-tests/panel'));
    $this->assertAuthenticatedAs(User::query()->where('email', 'just-signed-up@example.com')->sole());
});

test('AC-SIGNUP-1: after an hour the sign-up flow is gone too', function (): void {
    config(['nexo-sso.auth_mode' => 'sso']);
    $nonce = null;
    nexoSsoSignupProvider($nonce);

    $signup = nexoSsoAuthorizeQuery($this->get(route('nexo-sso.enter', ['to' => '/x', 'signup' => '1'])));
    $nonce = $signup['nonce'];
    $this->travel(61)->minutes();

    $this->get(route('nexo-sso.callback', ['code' => 'signup-code', 'state' => $signup['state']]))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('nexo_sso');
    $this->assertGuest();
});

test('AC-SIGNUP-2: a session Nexo ID governs goes straight to `to` (no provider call, no prompt)', function (string $mode): void {
    config(['nexo-sso.auth_mode' => $mode, 'nexo-sso.transition_until' => now()->addMonth()->format('Y-m-d')]);
    Http::fake();

    $this->withSession(nexoSsoSignedIn($this, User::factory()->create()))
        ->get(route('nexo-sso.enter', ['to' => '/some/page', 'signup' => '1']))
        ->assertRedirect(url('/some/page'));

    Http::assertNothingSent();
})->with(['local', 'transition', 'sso']);

test('AC-SIGNUP-2: a password session in transition or local is let in, as enter does', function (string $mode): void {
    config(['nexo-sso.auth_mode' => $mode, 'nexo-sso.transition_until' => now()->addMonth()->format('Y-m-d')]);
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->get(route('nexo-sso.enter', ['to' => '/x', 'signup' => '1']))
        ->assertRedirect(url('/x'));

    Http::assertNothingSent();
})->with(['transition', 'local']);

test('AC-SIGNUP-2: in sso a password session (it has no place there) goes through Nexo ID with prompt=create', function (): void {
    config(['nexo-sso.auth_mode' => 'sso']);
    nexoSsoFakeProvider();

    $query = nexoSsoAuthorizeQuery($this->actingAs(User::factory()->create())
        ->get(route('nexo-sso.enter', ['to' => '/x', 'signup' => '1'])));

    expect($query['prompt'] ?? null)->toBe('create');
});

test('AC-SIGNUP-3: a hostile `to` lands on the panel (home), signed in or not', function (string $to): void {
    nexoSsoFakeProvider();

    $query = nexoSsoAuthorizeQuery($this->get(route('nexo-sso.enter').'?signup=1&to='.rawurlencode($to)));
    expect($query['prompt'] ?? null)->toBe('create')
        ->and(nexoSsoStoredFlow($query['state'])['return'])->toBe(config('nexo-sso.home'));

    $this->withSession(nexoSsoSignedIn($this, User::factory()->create()))
        ->get(route('nexo-sso.enter').'?signup=1&to='.rawurlencode($to))
        ->assertRedirect(url(config('nexo-sso.home')));
})->with([
    'absolute https' => 'https://evil.test/phish',
    'protocol-relative' => '//evil.test/phish',
    'backslash host' => '/\\evil.test',
    'javascript scheme' => 'javascript:alert(1)',
    'loop into auth/nexo' => '/auth/nexo/enter?to=/x&signup=1',
]);

test('AC-SIGNUP-4: any other value of `signup` is ignored — a plain sign-in, no prompt', function (string $query): void {
    nexoSsoFakeProvider();

    $authorize = nexoSsoAuthorizeQuery($this->get(route('nexo-sso.enter').'?to=/x&'.$query));

    expect($authorize)->not->toHaveKey('prompt')
        ->and(nexoSsoStoredFlow($authorize['state'])['return'])->toBe('/x');
})->with([
    'zero' => 'signup=0',
    'true' => 'signup=true',
    'yes' => 'signup=yes',
    'empty' => 'signup=',
    'two' => 'signup=2',
    'padded' => 'signup=01',
    'array' => 'signup[]=1',
    'a prompt smuggled in' => 'signup=none',
    'no signup at all' => 'tab=1',
]);

test('AC-SIGNUP-5: in transition, a new Nexo ID that matches no account gets «First time in <App>?», as today', function (): void {
    config(['nexo-sso.auth_mode' => 'transition', 'nexo-sso.transition_until' => now()->addMonth()->format('Y-m-d')]);

    nexoSsoSignUpAndReturn($this, '/nexo-sso-tests/panel')->assertRedirect(route('nexo-sso.new-account'));

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);

    // «Create my account» then finishes it, back on `to`.
    $this->post(route('nexo-sso.new-account.store'), ['choice' => 'create'])
        ->assertRedirect(url('/nexo-sso-tests/panel'));
    $this->assertAuthenticatedAs(User::query()->where('email', 'just-signed-up@example.com')->sole());
});

test('AC-SIGNUP-5: in transition, signing up with the email of an existing account joins it (verified email), no question', function (): void {
    config(['nexo-sso.auth_mode' => 'transition', 'nexo-sso.transition_until' => now()->addMonth()->format('Y-m-d')]);
    $existing = User::factory()->create(['email' => 'just-signed-up@example.com']);

    nexoSsoSignUpAndReturn($this, '/nexo-sso-tests/panel')->assertRedirect(url('/nexo-sso-tests/panel'));

    $this->assertAuthenticatedAs($existing);
    expect($existing->fresh()->nexo_id_sub)->toBe('signed-up-sub')
        ->and(User::query()->count())->toBe(1);
});

test('AC-SIGNUP-6: with SSO off it does not answer and touches no network', function (): void {
    config(['nexo-sso.enabled' => false]);
    Http::fake();

    $this->get('/auth/nexo/enter?to=/x&signup=1')->assertNotFound();

    Http::assertNothingSent();
});

test('AC-SIGNUP-7: with the provider unreachable the visitor gets the friendly error, never a 500', function (): void {
    Http::fake(fn () => throw new ConnectionException('provider down'));

    $this->get(route('nexo-sso.enter', ['to' => '/x', 'signup' => '1']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('nexo_sso');
});
