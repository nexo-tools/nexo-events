<?php

use App\Models\User;
use App\Services\NexoSso\NexoSsoFlows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/NexoSsoTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'nexo-sso.enabled' => true,
        'nexo-sso.issuer' => 'https://nexoid.test',
        'nexo-sso.client_id' => '11111111-2222-3333-4444-555555555555',
    ]);
    Route::middleware(['web', 'auth'])->get('/nexo-sso/probe', fn () => 'ok');
});

test('AC-SESS-1: a successful callback establishes a tool-owned session that needs no further provider calls', function (): void {
    nexoSsoFakeProvider();
    nexoSsoCallback($this)->assertRedirect(config('nexo-sso.home'));
    $this->assertAuthenticated();

    // From here on the provider is gone — the local session alone suffices.
    Http::fake(fn () => throw new ConnectionException('provider down'));
    $this->get('/nexo-sso/probe')->assertOk();
});

test('AC-SESS-2: local logout ends only the tool session and never calls the provider', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    Http::fake();

    Auth::logout();

    $this->assertGuest();
    Http::assertNothingSent(); // central logout is the provider's own concern
});

test('AC-SESS-3: the callback stores sub, sid, id_token, authenticated_at and checked_at', function (): void {
    $this->travelTo(now()->setTime(10, 0));
    $token = nexoSsoIdToken(['sid' => 'nexo-sid-abc']);
    nexoSsoFakeProvider(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => $token]);

    nexoSsoCallback($this)->assertRedirect();

    expect(session('nexo_sso.sub'))->toBe('user-uuid-0001')
        ->and(session('nexo_sso.sid'))->toBe('nexo-sid-abc')
        ->and(session('nexo_sso.id_token'))->toBe($token)
        ->and(session('nexo_sso.authenticated_at'))->toBe(now()->getTimestamp())
        ->and(session('nexo_sso.checked_at'))->toBe(now()->getTimestamp());
});

test('AC-SESS-3: an id_token without sid (provider before back-channel) still signs in, with no sid stored', function (): void {
    nexoSsoFakeProvider(['access_token' => 'fake', 'token_type' => 'Bearer', 'id_token' => nexoSsoIdToken(['sid' => null])]);

    nexoSsoCallback($this)->assertRedirect();

    $this->assertAuthenticated();
    expect(session('nexo_sso.sub'))->toBe('user-uuid-0001')
        ->and(session('nexo_sso.sid'))->toBeNull();
});

test('AC-SESS-3: a sign-in gets a fresh session id (no session fixation)', function (): void {
    nexoSsoFakeProvider();
    $this->withSession(nexoSsoFlowSession());
    $before = session()->getId();

    $this->get(route('nexo-sso.callback', ['code' => 'auth-code', 'state' => nexoSsoState()]))->assertRedirect();

    $this->assertAuthenticated();
    expect(session()->getId())->not->toBe($before);
});

test('AC-SESS-4: a sign-in never sets a tool remember-me cookie', function (): void {
    nexoSsoFakeProvider();

    $response = nexoSsoCallback($this);

    $this->assertAuthenticated();
    $names = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
    expect(collect($names)->filter(fn (string $name) => str_starts_with($name, 'remember_'))->all())->toBe([]);
});

test('AC-SESS-5: the callback is not guest-only — an interactive callback while signed in switches to the Nexo ID account', function (): void {
    nexoSsoFakeProvider();
    $this->actingAs(User::factory()->create(['email' => 'someone-else@example.com']));

    nexoSsoCallback($this, mode: NexoSsoFlows::INTERACTIVE)->assertRedirect(config('nexo-sso.home'));

    expect(auth()->user()->email)->toBe('user@example.com');
});
