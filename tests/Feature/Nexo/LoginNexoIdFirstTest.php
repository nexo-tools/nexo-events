<?php

use App\Support\NexoChrome;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;

// Guardian (chrome v2, 2026-11-26): with SSO on, «Continuar con Nexo ID» is the
// first and only primary action of the sign-in screen, and the password form
// follows NEXO_AUTH_MODE (CONTRATO-F3 §2.7, §5.4):
//
//   local       Nexo ID first, then the password form
//   transition  Nexo ID first, the password form folded, no «Recordarme»
//   sso         Nexo ID only: no password field on the page
//
// Why it exists: four of the five tools put the local form first and Nexo ID
// last, under an «o» (H-cruce-08). People typed their Nexo ID password into a
// form that could never accept it, got «credenciales no coinciden» and no hint.
//
// It checks the rendered screen, not the component: <x-nexo-sign-in-options>
// is THE login component of the family (CONTRATO-F3 §0.1), but what a person
// meets is the screen the app builds around it. Copy into tests/Feature/Nexo/.
// Nothing to adapt: an app without an SSO client (Nexo ID itself) skips.

function loginNexoIdFirstPage(): ?string
{
    if (! Route::has('login')) {
        return null;
    }

    $response = test()->get(route('login'));

    return $response->status() === 200 ? $response->getContent() : null;
}

/** Offset of the «Continuar con Nexo ID» anchor (href = the interactive authorize), or false. */
function loginNexoIdFirstSsoAnchor(string $html, ?array &$tag = null)
{
    $href = preg_quote('href="'.NexoChrome::ssoSignInUrl().'"', '/');
    if (! preg_match('/<a\b[^>]*'.$href.'[^>]*>/s', $html, $m, PREG_OFFSET_CAPTURE)) {
        return false;
    }
    $tag = $m[0][0];

    return $m[0][1];
}

function loginNexoIdFirstSkipWithoutSso(): void
{
    if (NexoChrome::ssoSignInUrl() === null) {
        test()->markTestSkipped('No SSO client in this app (Nexo ID is the IdP, or NEXO_SSO_ENABLED=false at boot).');
    }
}

/**
 * Put this app in an auth mode for the rest of the test, through the same keys
 * the sso-client's NexoAuthMode reads. A transition gets a cut-over a month
 * away, so it is still a transition today.
 */
function loginNexoIdFirstMode(string $mode): void
{
    config()->set('nexo-sso.enabled', true);
    config()->set('nexo-sso.issuer', 'https://id.example.test');
    config()->set('nexo-sso.auth_mode', $mode);
    config()->set('nexo-sso.transition_until', now()->addMonth()->format('Y-m-d'));

    expect(NexoChrome::authMode())->toBe($mode, "NEXO_AUTH_MODE={$mode} does not read as [{$mode}].");
}

// Every mode, not only the one this machine happens to run: production runs
// sso in four tools and transition in one, while the suite runs local — a
// guardian that only looks at the current mode never sees those two screens.
it('puts «Continuar con Nexo ID» first, as the only primary action', function (string $mode) {
    loginNexoIdFirstSkipWithoutSso();
    loginNexoIdFirstMode($mode);

    $html = loginNexoIdFirstPage();
    if ($html === null) {
        test()->markTestSkipped("The login screen does not render in {$mode} mode.");
    }

    // Inside the card: in sso mode the header's «Iniciar sesión» points at the
    // same authorize URL, and it is (rightly) a ghost.
    $start = strpos($html, 'data-nexo-auth-card');
    expect($start)->not->toBeFalse('The login screen has no nexo-auth-card.');
    $card = substr($html, (int) $start);
    $card = substr($card, 0, (int) (strpos($card, '</main>') ?: strlen($card)));

    $sso = loginNexoIdFirstSsoAnchor($card, $tag);
    expect($sso)->not->toBeFalse('The sign-in card has no «Continuar con Nexo ID» (x-nexo-sign-in-options).');

    $password = strpos($card, 'type="password"');
    if ($password !== false) {
        expect($sso)->toBeLessThan($password, '«Continuar con Nexo ID» must come before the password form.');
    }

    expect(str_contains((string) $tag, 'nexo-btn--primary'))->toBeTrue('«Continuar con Nexo ID» must be the primary button.');

    // One violet button per screen: the local submit steps down to ghost.
    expect(substr_count($card, 'nexo-btn--primary'))->toBe(1, 'The sign-in card has more than one primary button.');
})->with(['local', 'transition', 'sso']);

// Usability pass, F3 2026-11-26: only two of the five tools said what Nexo ID
// is, each in its own words; in the other three a newcomer met a «Continuar con
// Nexo ID» button and nothing else. The line is part of the component now.
it('says what Nexo ID is, before «Continuar con Nexo ID», in every mode', function (string $mode) {
    loginNexoIdFirstSkipWithoutSso();
    loginNexoIdFirstMode($mode);

    $html = loginNexoIdFirstPage();
    if ($html === null) {
        test()->markTestSkipped("The login screen does not render in {$mode} mode.");
    }

    // Inside the card: in sso mode the header's «Iniciar sesión» may point at
    // the same authorize URL.
    $card = substr($html, (int) strpos($html, 'data-nexo-auth-card'));

    // In the language the page was rendered in (another test may leave a
    // different locale behind in this process).
    $locale = preg_match('/<html[^>]*\blang="([a-z]{2})/', $html, $m) ? $m[1] : app()->getLocale();
    $explainer = strpos($card, 'data-nexo-id-explainer>'.e(__('nexo.auth.explainer', [], $locale)).'</p>');
    expect($explainer)->not->toBeFalse('The sign-in card does not say what Nexo ID is (nexo.auth.explainer).');
    expect(substr_count($html, 'data-nexo-id-explainer'))->toBe(1, 'The sign-in screen explains Nexo ID more than once.');
    expect($explainer)->toBeLessThan((int) loginNexoIdFirstSsoAnchor($card), 'The explanation goes before «Continuar con Nexo ID».');
})->with(['local', 'transition', 'sso']);

it('shapes the password form after the auth mode', function (string $mode) {
    loginNexoIdFirstSkipWithoutSso();
    loginNexoIdFirstMode($mode);

    $html = loginNexoIdFirstPage();

    if ($mode === 'sso') {
        if ($html !== null) {
            expect(str_contains($html, 'type="password"'))->toBeFalse('In sso mode the sign-in screen must not ask for a password.');
        }

        return;
    }

    expect($html)->not->toBeNull("The login screen must render in {$mode} mode.");

    if ($mode === 'transition') {
        $folded = preg_match_all('/<details\b.*?<\/details>/s', (string) $html, $folds) > 0
            && str_contains(implode('', $folds[0]), 'type="password"');
        expect($folded)->toBeTrue('In transition the password form is folded (<details>) under «Usar email y contraseña».');
        expect(preg_replace('/<details\b.*?<\/details>/s', '', (string) $html))->not->toContain('type="password"');
        expect(str_contains((string) $html, 'name="remember"'))->toBeFalse('In transition the tool does not offer its own «Recordarme».');

        return;
    }

    expect(str_contains((string) $html, 'type="password"'))->toBeTrue('In local mode the password form stays.');
    expect(str_contains((string) $html, '<details'))->toBeFalse('In local mode the password form is not folded away.');
})->with(['local', 'transition', 'sso']);

it('sends «Iniciar sesión» and «Crear cuenta» where the mode says', function (string $mode) {
    loginNexoIdFirstSkipWithoutSso();
    loginNexoIdFirstMode($mode);

    // «Crear cuenta»: the local sign-up in local mode (if the instance keeps it
    // open); outside it, Nexo ID's sign-up THROUGH this tool (v2.2), so the
    // person comes back here signed in — see the next test.
    if ($mode === 'local') {
        $open = (bool) config('nexo.allow_registration', true);
        expect(NexoChrome::signUpUrl())->toBe($open && Route::has('register') ? route('register') : null, 'In local mode «Crear cuenta» is the local sign-up.');
    } else {
        loginNexoIdFirstAssertSignUpComesBack($mode);
    }

    // «Iniciar sesión»: straight to Nexo ID in sso (no /login on the way), the
    // login screen otherwise (§5.1).
    $expected = $mode === 'sso'
        ? route(Route::has('nexo-sso.enter') ? 'nexo-sso.enter' : 'nexo-sso.redirect')
        : (Route::has('login') ? route('login') : null);
    expect(NexoChrome::signInUrl())->toBe($expected, "In {$mode} mode «Iniciar sesión» goes to the wrong place.");

    $header = (string) test()->get(url('/'))->getContent();
    expect(preg_match('/<a href="([^"]*)"[^>]*data-nexo-sign-in>/', $header, $m))->toBe(1, 'The guest header has no «Iniciar sesión».');
    expect(html_entity_decode($m[1]))->toBe($expected, "In {$mode} mode the header's «Iniciar sesión» does not follow the mode.");
})->with(['local', 'transition', 'sso']);

/**
 * v2.2, «alta que vuelve a la tool»: outside local mode «Crear cuenta» is
 * GET /auth/nexo/enter?to=<home>&signup=1 — the tool asks Nexo ID for its
 * sign-up (prompt=create) and, once the account exists, the authorize goes on
 * and lands back here. Before v2.2 it linked <issuer>/register and the person
 * never came back. Without the entry route (an sso-client before v2) it is
 * still Nexo ID's /register.
 */
function loginNexoIdFirstAssertSignUpComesBack(string $mode): void
{
    $url = (string) NexoChrome::signUpUrl();

    if (! Route::has('nexo-sso.enter')) {
        expect($url)->toBe('https://id.example.test/register', "In {$mode} mode, without the entry route, «Crear cuenta» is Nexo ID's /register.");

        return;
    }

    $parts = parse_url($url);
    parse_str((string) ($parts['query'] ?? ''), $query);
    $base = strtok($url, '?');

    expect($base)->toBe(route('nexo-sso.enter'), "In {$mode} mode «Crear cuenta» must go through this tool's entry route, not to Nexo ID directly.");
    expect($query['signup'] ?? null)->toBe('1', "In {$mode} mode «Crear cuenta» must ask for Nexo ID's sign-up (signup=1).");
    expect($query['to'] ?? null)->toBe(NexoChrome::ssoHome(), "In {$mode} mode «Crear cuenta» must come back to this app's home (nexo-sso.home).");
    expect(NexoChrome::ssoHome())->toStartWith('/');
}

it('brings a new account back to this tool, wherever «Crear cuenta» is offered', function (string $mode) {
    loginNexoIdFirstSkipWithoutSso();
    loginNexoIdFirstMode($mode);

    loginNexoIdFirstAssertSignUpComesBack($mode);

    // Every «Crear cuenta» of the chrome says the same: the login card and the
    // phone sheet of a guest.
    $expected = e((string) NexoChrome::signUpUrl());
    $sheet = Blade::render('<x-nexo-mobile-menu />');
    expect(str_contains($sheet, 'href="'.$expected.'" class="nexo-sheet__item" data-nexo-sheet-sign-up'))
        ->toBeTrue("In {$mode} mode the phone sheet's «Crear cuenta» does not come back to the tool.");

    $html = loginNexoIdFirstPage();
    if ($html !== null) {
        expect(str_contains($html, 'href="'.$expected.'" data-nexo-sign-up'))
            ->toBeTrue("In {$mode} mode the login card's «Crear cuenta» does not come back to the tool.");
    }
})->with(['transition', 'sso']);

it('keeps «Crear cuenta» away from a closed local sign-up', function () {
    // NEXO_ALLOW_REGISTRATION=false (nexo.allow_registration): the local
    // /register answers 404, so local mode offers no «Crear cuenta» at all.
    config()->set('nexo-sso.enabled', false);
    config()->set('nexo.allow_registration', false);
    expect(NexoChrome::signUpUrl())->toBeNull('With the local sign-up closed, «Crear cuenta» must not link a 404.');

    // Read like the gate reads it, `(bool)`: an empty or null value closes it
    // there, so it must not leave a «Crear cuenta» pointing at the 404 here.
    foreach (['', null, '0'] as $closed) {
        config()->set('nexo.allow_registration', $closed);
        expect(NexoChrome::signUpUrl())->toBeNull('nexo.allow_registration = '.var_export($closed, true).' closes the sign-up.');
    }

    config()->set('nexo.allow_registration', true);
    expect(NexoChrome::signUpUrl())->toBe(Route::has('register') ? route('register') : null);
});

it('only ever sends «Crear cuenta» back to a path of this app', function () {
    loginNexoIdFirstSkipWithoutSso();
    loginNexoIdFirstMode('sso');

    // "/\t/evil.example" is "//evil.example" to a browser (it drops tabs and
    // newlines from a URL); /auth/nexo/* would loop through the entry route.
    foreach (['https://evil.example/', '//evil.example/x', '/\\evil.example', "/\t/evil.example", "/\n/evil.example", '/auth/nexo/enter', 'app', ''] as $home) {
        config()->set('nexo-sso.home', $home);
        expect(NexoChrome::ssoHome())->toBe('/', 'nexo-sso.home = '.json_encode($home).' must fall back to \'/\'.');
    }

    config()->set('nexo-sso.home', '/somewhere/inside');
    expect(NexoChrome::ssoHome())->toBe('/somewhere/inside');
});

it('reads the mode the way the sso-client does', function () {
    config()->set('nexo-sso.enabled', true);

    // A transition is over at the start of the day after NEXO_AUTH_TRANSITION_UNTIL.
    config()->set('nexo-sso.auth_mode', 'transition');
    config()->set('nexo-sso.transition_until', now()->format('Y-m-d'));
    expect(NexoChrome::authMode())->toBe('transition', 'NEXO_AUTH_TRANSITION_UNTIL is the LAST day passwords work: today is still a transition.');
    config()->set('nexo-sso.transition_until', now()->subDay()->format('Y-m-d'));
    expect(NexoChrome::authMode())->toBe('sso', 'After NEXO_AUTH_TRANSITION_UNTIL a transition behaves as sso.');
    config()->set('nexo-sso.transition_until', 'someday');
    expect(NexoChrome::authMode())->toBe('transition', 'A transition without a valid date has no end.');

    // Unknown values are local, and SSO off is always local (never a lock-out).
    config()->set('nexo-sso.auth_mode', 'both');
    expect(NexoChrome::authMode())->toBe('local');
    config()->set('nexo-sso.auth_mode', 'sso');
    config()->set('nexo-sso.enabled', false);
    expect(NexoChrome::authMode())->toBe('local', 'With SSO off the mode is local, whatever NEXO_AUTH_MODE says.');
});

// «Recordarme» is a credential Nexo ID cannot revoke: only local mode (and
// standalone) offers it. NexoChrome::allowsRemember() is what the login view
// asks; with session v2 ported it is NexoAuthMode's answer.
it('offers «Recordarme» only where NexoChrome::allowsRemember() says so', function () {
    $expected = ['local' => true, 'transition' => false, 'sso' => false];

    foreach ($expected as $mode => $allows) {
        loginNexoIdFirstMode($mode);
        expect(NexoChrome::allowsRemember())->toBe($allows, "In {$mode} mode «Recordarme» must be ".($allows ? 'offered.' : 'hidden.'));

        $owner = 'App\\Services\\NexoSso\\NexoAuthMode';
        if (class_exists($owner)) {
            expect(NexoChrome::allowsRemember())->toBe((bool) $owner::allowsRemember(), 'NexoChrome must defer to NexoAuthMode::allowsRemember().');
        }

        $html = loginNexoIdFirstPage();
        if ($html !== null && ! $allows) {
            expect(str_contains($html, 'name="remember"'))->toBeFalse("In {$mode} mode the login screen offers «Recordarme».");
        }
    }

    config()->set('nexo-sso.enabled', false);
    expect(NexoChrome::allowsRemember())->toBeTrue('Standalone (SSO off), «Recordarme» is always offered.');
});

it('opens the transition fold from the start when the login view asks for it', function () {
    // v2.2: `password-open` is for the person who chose «Ya tengo una cuenta
    // con otro email» — they came to type a password. Closed by default.
    loginNexoIdFirstSkipWithoutSso();
    loginNexoIdFirstMode('transition');
    view()->share('errors', new ViewErrorBag);

    $fold = function (string $props): string {
        $html = Blade::render('<x-nexo-sign-in-options '.$props.'><input type="password" name="password"></x-nexo-sign-in-options>');
        expect(preg_match('/<details\b[^>]*data-nexo-password-fold[^>]*>/', $html, $m))->toBe(1, 'In transition the password form is folded.');

        return $m[0];
    };

    expect($fold(''))->not->toMatch('/\sopen\b/', 'The fold starts closed by default.');
    expect($fold(':password-open="false"'))->not->toMatch('/\sopen\b/');
    expect($fold('password-open="false"'))->not->toMatch('/\sopen\b/', 'A plain password-open="false" is text, and it means closed.');
    expect($fold(':password-open="true"'))->toMatch('/\sopen\b/', 'password-open must open the fold.');

    // Nowhere else does it change anything: local is never folded, sso has no form.
    loginNexoIdFirstMode('local');
    $local = Blade::render('<x-nexo-sign-in-options :password-open="true"><input type="password" name="password"></x-nexo-sign-in-options>');
    expect(str_contains($local, '<details'))->toBeFalse();
    loginNexoIdFirstMode('sso');
    $sso = Blade::render('<x-nexo-sign-in-options :password-open="true"><input type="password" name="password"></x-nexo-sign-in-options>');
    expect(str_contains($sso, 'type="password"'))->toBeFalse();
});

it('keeps the plain password form when SSO is switched off', function () {
    if (loginNexoIdFirstPage() === null) {
        test()->markTestSkipped('This app has no login screen.');
    }

    config()->set('nexo-sso.enabled', false);
    $html = (string) loginNexoIdFirstPage();

    expect(str_contains($html, '/auth/nexo/'))->toBeFalse('Standalone, there is no Nexo ID button.');
    expect(str_contains($html, 'type="password"'))->toBeTrue('Standalone, the password form is the way in.');
});
