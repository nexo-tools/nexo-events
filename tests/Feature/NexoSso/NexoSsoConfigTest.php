<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
});

test('AC-CFG-1: with SSO disabled the endpoints don\'t answer and no network is touched', function (): void {
    config(['nexo-sso.enabled' => false]);
    Http::fake();

    // A GET is a 404. A POST is a 404 here (the routes exist, the controller
    // says no) or a 405 in a real standalone boot, where the routes don't
    // exist and the tool's GET-only Route::fallback answers any other method
    // with «method not allowed» (CONTRATO-F3 §0.1). Never a 2xx/3xx.
    $absent = fn ($response) => expect($response->status())->toBeIn([404, 405]);

    $this->get('/auth/nexo/redirect')->assertNotFound();
    $this->get('/auth/nexo/callback')->assertNotFound();
    $this->get('/auth/nexo/enter')->assertNotFound();
    $this->get('/auth/nexo/enter?to=/x&signup=1')->assertNotFound();
    $this->get('/auth/nexo/signed-out')->assertNotFound();
    $absent($this->post('/auth/nexo/backchannel-logout', ['logout_token' => 'x']));
    $this->get('/auth/nexo/new-account')->assertNotFound();
    $absent($this->post('/auth/nexo/new-account', ['choice' => 'create']));
    $absent($this->actingAs(User::factory()->create())->post('/auth/nexo/link'));
    $this->get('/auth/nexo/link/confirm')->assertNotFound();
    $absent($this->post('/auth/nexo/link/confirm', ['choice' => 'link']));
    Http::assertNothingSent();
});

test('AC-CFG-1: with SSO disabled the routes file registers no route at all', function (): void {
    config(['nexo-sso.enabled' => false]);
    $before = count(Route::getRoutes()->getRoutes());

    require base_path('routes/nexo-sso.php');

    expect(count(Route::getRoutes()->getRoutes()))->toBe($before);
});

test('AC-CFG-2: endpoints come from the discovery document and the document is cached', function (): void {
    nexoSsoFakeProvider();

    $first = $this->get(route('nexo-sso.redirect'));
    $first->assertRedirect();
    expect($first->headers->get('Location'))->toStartWith('https://nexoid.test/oauth/authorize?');

    $this->get(route('nexo-sso.redirect'))->assertRedirect();

    Http::assertSentCount(1); // second flow start reused the cached discovery
});

test('AC-CFG-2: the JWKS is fetched once and cached across logins', function (): void {
    nexoSsoFakeProvider();

    // Two successful callbacks (second matches the first by sub). The JWKS is
    // read during id_token validation; jwks_ttl must cache it so a second login
    // does not re-fetch it.
    nexoSsoCallback($this);
    nexoSsoCallback($this);

    expect(Http::recorded(fn ($request) => str_contains($request->url(), '/oauth/jwks')))->toHaveCount(1);
});
