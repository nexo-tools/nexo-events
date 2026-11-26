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

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))->toStartWith('https://nexoid.test/oauth/authorize?');
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

    expect((string) $response->headers->get('Location'))->toStartWith('https://nexoid.test/oauth/authorize?');
});
