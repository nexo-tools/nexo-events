<?php

// Auth modes (NEXO_AUTH_MODE = local | transition | sso, §2.7): what each
// credential surface does and where `auth` sends a guest. Throwaway routes, so
// the suite doesn't depend on the tool's own auth controllers (those get their
// own tests). The login screen itself is nexo-ui's <x-nexo-sign-in-options>
// (its guardian LoginNexoIdFirstTest). (AC-AUTHMODE)

use App\Http\Middleware\EnsureLocalAuth;
use App\Services\NexoSso\NexoAuthMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/NexoSsoTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'nexo-sso.enabled' => true,
        'nexo-sso.issuer' => 'https://nexoid.test',
        'nexo-sso.client_id' => '11111111-2222-3333-4444-555555555555',
        'nexo-sso.auth_mode' => 'local',
        'nexo-sso.transition_until' => '',
    ]);

    Route::middleware('web')->group(function (): void {
        Route::post('/nexo-sso-tests/login', fn () => 'login-ok')->middleware(EnsureLocalAuth::class);
        Route::match(['get', 'post'], '/nexo-sso-tests/register', fn () => 'register-ok')->middleware(EnsureLocalAuth::class.':register');
        Route::post('/nexo-sso-tests/forgot', fn () => 'password-ok')->middleware(EnsureLocalAuth::class.':password');
    });
});

test('AC-AUTHMODE-1: local mode keeps every local surface open', function (): void {
    $this->post('/nexo-sso-tests/login')->assertOk();
    $this->get('/nexo-sso-tests/register')->assertOk();
    $this->post('/nexo-sso-tests/register')->assertOk();
    $this->post('/nexo-sso-tests/forgot')->assertOk();

    expect(NexoAuthMode::allowsRemember())->toBeTrue();
});

test('AC-AUTHMODE-2: sso mode answers 404 on local login, registration and reset', function (): void {
    config(['nexo-sso.auth_mode' => 'sso']);

    $this->post('/nexo-sso-tests/login')->assertNotFound();
    $this->get('/nexo-sso-tests/register')->assertNotFound();
    $this->post('/nexo-sso-tests/register')->assertNotFound();
    $this->post('/nexo-sso-tests/forgot')->assertNotFound();

    expect(NexoAuthMode::allowsRemember())->toBeFalse();
});

test('AC-AUTHMODE-3: transition keeps password login and reset, closes local sign-up (GET → Nexo ID sign-up)', function (): void {
    config(['nexo-sso.auth_mode' => 'transition', 'nexo-sso.transition_until' => now()->addMonth()->format('Y-m-d')]);

    $this->post('/nexo-sso-tests/login')->assertOk();
    $this->post('/nexo-sso-tests/forgot')->assertOk();
    $this->get('/nexo-sso-tests/register')->assertRedirect('https://nexoid.test/register');
    $this->post('/nexo-sso-tests/register')->assertNotFound();

    expect(NexoAuthMode::allowsRemember())->toBeFalse()
        ->and(NexoAuthMode::refusesPasswordFor('a-linked-sub'))->toBeTrue()
        ->and(NexoAuthMode::refusesPasswordFor(null))->toBeFalse();
});

test('AC-AUTHMODE-4: transition becomes sso the day after NEXO_AUTH_TRANSITION_UNTIL', function (): void {
    config(['nexo-sso.auth_mode' => 'transition', 'nexo-sso.transition_until' => '2027-01-31']);

    $this->travelTo(now()->setDate(2027, 1, 31)->setTime(23, 59, 59));
    expect(NexoAuthMode::current())->toBe(NexoAuthMode::TRANSITION);

    $this->travelTo(now()->setDate(2027, 2, 1)->setTime(0, 0, 0));
    expect(NexoAuthMode::current())->toBe(NexoAuthMode::SSO);
    $this->post('/nexo-sso-tests/login')->assertNotFound();
});

test('AC-AUTHMODE-5: with SSO disabled the effective mode is local (an instance never locks itself out)', function (string $configured): void {
    config(['nexo-sso.enabled' => false, 'nexo-sso.auth_mode' => $configured]);

    expect(NexoAuthMode::current())->toBe(NexoAuthMode::LOCAL);
    $this->post('/nexo-sso-tests/login')->assertOk();
})->with(['sso', 'transition']);

test('AC-AUTHMODE-5: unknown values (the old `both`) mean local', function (): void {
    config(['nexo-sso.auth_mode' => 'both']);

    expect(NexoAuthMode::current())->toBe(NexoAuthMode::LOCAL);
});

test('AC-AUTHMODE-6: in sso mode `auth` sends a guest deep link through /auth/nexo/enter?to=<itself>', function (): void {
    config(['nexo-sso.auth_mode' => 'sso']);

    $request = Request::create('/some/deep/link?tab=2');
    expect(NexoAuthMode::guestRedirectUrl($request))->toBe(route('nexo-sso.enter', ['to' => '/some/deep/link?tab=2']));

    $post = Request::create('/some/form', 'POST');
    expect(NexoAuthMode::guestRedirectUrl($post))->toBe(route('nexo-sso.enter'));
});

test('AC-AUTHMODE-6: in local and transition modes `auth` sends a guest to /login', function (string $mode): void {
    config(['nexo-sso.auth_mode' => $mode]);

    expect(NexoAuthMode::guestRedirectUrl(Request::create('/x')))->toBe(route('login'));
})->with(['local', 'transition']);
