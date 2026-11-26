<?php

// GET /auth/nexo/signed-out — where every sign-out ends (§2.5). (AC-SIGNEDOUT)

use App\Http\Controllers\Auth\NexoSsoController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/NexoSsoTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'nexo-sso.enabled' => true,
        'nexo-sso.issuer' => 'https://nexoid.test',
        'nexo-sso.client_id' => '11111111-2222-3333-4444-555555555555',
    ]);
});

test('AC-SIGNEDOUT-1: by default it says the person signed out of all of Nexo, with "Sign in again" and the home page', function (): void {
    $this->get(route('nexo-sso.signed-out'))
        ->assertOk()
        ->assertSee(__('You signed out of all of Nexo'))
        ->assertSee(__('Your session ended in Nexo ID and in every Nexo tool on this device.'))
        ->assertSee(__('Sign in again'))
        ->assertSee(route('nexo-sso.enter'), false)
        ->assertSee(__('Go to the home page'))
        ->assertSee('data-nexo-sso-signed-out="signed_out"', false);
});

test('AC-SIGNEDOUT-2: reason=ended says the Nexo ID session ended', function (): void {
    $this->get(route('nexo-sso.signed-out', ['reason' => 'ended']))
        ->assertOk()
        ->assertSee(__('Your Nexo ID session ended'))
        ->assertSee(__('For your security, we also closed your session here. Sign in again to continue.'))
        ->assertDontSee(__('You signed out of all of Nexo'));
});

test('AC-SIGNEDOUT-2: an unknown reason falls back to the default text', function (): void {
    $this->get(route('nexo-sso.signed-out', ['reason' => '<script>']))
        ->assertOk()
        ->assertSee('data-nexo-sso-signed-out="signed_out"', false)
        ->assertDontSee('&lt;script&gt;', false); // the reason is never echoed
});

test('AC-SIGNEDOUT-3: it renders in Spanish and Portuguese too', function (string $locale, string $text): void {
    app()->setLocale($locale);

    expect(__('You signed out of all of Nexo'))->toBe($text);
})->with([
    ['es', 'Cerraste sesión en todo Nexo'],
    ['pt', 'Você saiu de todo o Nexo'],
]);

test('AC-SIGNEDOUT-4: it is never cached and never indexed', function (): void {
    $response = $this->get(route('nexo-sso.signed-out'));

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    $response->assertHeader('X-Robots-Tag', 'noindex');
});

test('AC-SIGNEDOUT-4: the controller sets no-store and noindex itself, whatever the tool\'s header middleware does', function (): void {
    // Served from a path outside /auth/nexo, so a tool middleware that marks
    // the auth screens no-store can't be the one setting it.
    Route::middleware('web')->get('/nexo-sso-tests/signed-out', [NexoSsoController::class, 'signedOut']);

    $response = $this->get('/nexo-sso-tests/signed-out')->assertOk();

    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    $response->assertHeader('X-Robots-Tag', 'noindex');
});

test('AC-SIGNEDOUT-5: it does not sign anyone out by itself (a GET is never a logout)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('nexo-sso.signed-out'))->assertOk();

    $this->assertAuthenticatedAs($user);
});
