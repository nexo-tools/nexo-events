<?php

use App\Support\NexoChrome;
use Illuminate\Support\Facades\Route;

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
// It checks the rendered screen, not a component: the pattern ships both as
// nexo-ui's <x-nexo-sign-in-options> and as the sso-client's <x-nexo-sso.login>,
// and an app renders one of them. Copy into tests/Feature/Nexo/. Nothing to
// adapt: an app without an SSO client (Nexo ID itself) skips.

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
    expect($sso)->not->toBeFalse('The sign-in card has no «Continuar con Nexo ID» (x-nexo-sign-in-options or x-nexo-sso.login).');

    $password = strpos($card, 'type="password"');
    if ($password !== false) {
        expect($sso)->toBeLessThan($password, '«Continuar con Nexo ID» must come before the password form.');
    }

    expect(str_contains((string) $tag, 'nexo-btn--primary'))->toBeTrue('«Continuar con Nexo ID» must be the primary button.');

    // One violet button per screen: the local submit steps down to ghost.
    expect(substr_count($card, 'nexo-btn--primary'))->toBe(1, 'The sign-in card has more than one primary button.');
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

    // «Crear cuenta»: Nexo ID's sign-up outside local mode (§5.4).
    if ($mode === 'local') {
        expect(NexoChrome::signUpUrl())->toBe(Route::has('register') ? route('register') : null, 'In local mode «Crear cuenta» is the local sign-up.');
    } else {
        expect(NexoChrome::signUpUrl())->toBe('https://id.example.test/register', "In {$mode} mode «Crear cuenta» is Nexo ID's sign-up.");
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

it('keeps the plain password form when SSO is switched off', function () {
    if (loginNexoIdFirstPage() === null) {
        test()->markTestSkipped('This app has no login screen.');
    }

    config()->set('nexo-sso.enabled', false);
    $html = (string) loginNexoIdFirstPage();

    expect(str_contains($html, '/auth/nexo/'))->toBeFalse('Standalone, there is no Nexo ID button.');
    expect(str_contains($html, 'type="password"'))->toBeTrue('Standalone, the password form is the way in.');
});
