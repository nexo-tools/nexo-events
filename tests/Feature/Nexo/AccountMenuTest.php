<?php

use App\Support\NexoChrome;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;

// Guardian (chrome v2, 2026-11-26): the account menu is the same in the 6 apps,
// in the same order (CONTRATO-F3 §5.2):
//
//   name + email · Tu panel (this app's name) · Tu cuenta Nexo ID · Centro de
//   ayuda · separator · Cerrar sesión
//
// Why it exists: the 2026-11-24 audit found the account and «Cerrar sesión» in
// five different shapes across six apps — a loose button in two, a menu with
// different items in two, nothing at all in one — and no app linked to the
// Nexo ID account. Whatever a person learned in one app did not work in the next.
//
// Copy into tests/Feature/Nexo/. Nothing to adapt here: the panel comes from
// config('nexo.chrome.panel_route') and lang nexo.app.panel, the rest from
// App\Support\NexoChrome. Users come from the app's own factory.
//
// Pest note: toContain() is variadic — a second argument is another needle, not
// a failure message — so human-readable messages go through toBeTrue()/toBe().

/** A signed-in person with a known name and email. */
function accountMenuSignIn(): object
{
    $model = config('auth.providers.users.model');
    $user = $model::factory()->create(['name' => 'Ana Vidal', 'email' => 'ana.vidal@example.test']);
    test()->actingAs($user);

    return $user;
}

/** Position of a marker in the html, failing loudly when it is missing. */
function accountMenuPosition(string $html, string $marker): int
{
    $position = strpos($html, $marker);
    expect($position)->not->toBeFalse("The account menu does not render [{$marker}].");

    return (int) $position;
}

it('declares the panel this app calls its own', function () {
    $route = NexoChrome::panelRoute();
    expect(Route::has($route))->toBeTrue("config('nexo.chrome.panel_route') = [{$route}] is not a route of this app.");

    foreach (['es', 'en', 'pt'] as $locale) {
        expect(Lang::has('nexo.app.panel', $locale, false))
            ->toBeTrue("lang/{$locale}/nexo.php has no app.panel — the menu's «Tu panel» must carry this app's own name.");
    }
});

it('lists the account in the canonical order', function () {
    config()->set('nexo-sso.enabled', true);
    config()->set('nexo-sso.issuer', 'https://id.example.test');
    accountMenuSignIn();

    $html = Blade::render('<x-nexo-account-menu />');

    $identity = accountMenuPosition($html, 'data-nexo-account-identity');
    $panel = accountMenuPosition($html, 'data-nexo-account-panel');
    $help = accountMenuPosition($html, 'data-nexo-account-help');
    $separator = accountMenuPosition($html, 'data-nexo-account-sep');
    $logout = accountMenuPosition($html, 'data-nexo-account-logout');

    $order = [$identity, $panel];
    if (NexoChrome::current() !== 'nexoid') {
        // In Nexo ID the panel IS the account, so item 3 does not exist there.
        $order[] = accountMenuPosition($html, 'data-nexo-account-nexoid');
        expect(str_contains($html, 'href="https://id.example.test/profile"'))
            ->toBeTrue('«Tu cuenta Nexo ID» must point at <issuer>/profile.');
    }
    array_push($order, $help, $separator, $logout);

    $sorted = $order;
    sort($sorted);
    expect($order)->toBe($sorted, 'The account menu is out of the canonical order (identity, panel, Nexo ID, help, separator, sign out).');

    expect(str_contains($html, 'Ana Vidal'))->toBeTrue('The menu does not show the name.');
    expect(str_contains($html, 'ana.vidal@example.test'))->toBeTrue('The menu does not show the email.');
    expect(str_contains($html, 'href="'.NexoChrome::panelUrl().'"'))->toBeTrue('«Tu panel» does not point at the panel route.');
    expect(str_contains($html, e(NexoChrome::panelLabel())))->toBeTrue('«Tu panel» does not carry this app\'s panel name.');
    expect(str_contains($html, e(__('nexo.account.nexo_id'))) || NexoChrome::current() === 'nexoid')->toBeTrue();
    expect(str_contains($html, e(__('nexo.account.sign_out'))))->toBeTrue('The menu does not say «Cerrar sesión».');
});

it('is a real menu: a labelled trigger that owns aria-expanded, and menu items', function () {
    accountMenuSignIn();

    $html = Blade::render('<x-nexo-account-menu />');

    expect(preg_match('/<button[^>]*aria-haspopup="menu"[^>]*>/s', $html, $trigger))->toBe(1, 'The account trigger has no aria-haspopup="menu".');
    expect(str_contains($trigger[0], 'aria-expanded'))->toBeTrue('The account trigger does not expose aria-expanded.');
    expect(str_contains($trigger[0], 'aria-label='))->toBeTrue('The account trigger is an avatar: it needs an aria-label.');
    expect(str_contains($html, 'x-data="nexoMenu"'))->toBeTrue('The account menu must reuse nexoMenu (keyboard + focus return).');
    expect(substr_count($html, 'role="menuitem"'))->toBeGreaterThanOrEqual(3);
});

it('signs out through the central logout when SSO is on, and the local one when it is off', function () {
    accountMenuSignIn();

    if (Route::has('nexo-sso.logout')) {
        config()->set('nexo-sso.enabled', true);
        $html = Blade::render('<x-nexo-account-menu />');
        expect(str_contains($html, 'action="'.route('nexo-sso.logout').'"'))
            ->toBeTrue('With SSO on, «Cerrar sesión» must POST to nexo-sso.logout (it closes Nexo ID too).');
    }

    if (Route::has('logout')) {
        config()->set('nexo-sso.enabled', false);
        $html = Blade::render('<x-nexo-account-menu />');
        expect(str_contains($html, 'action="'.route('logout').'"'))->toBeTrue('Standalone, «Cerrar sesión» must POST to logout.');
        expect(str_contains($html, 'data-nexo-account-nexoid'))->toBeFalse('Standalone there is no Nexo ID account to link to.');
    }

    expect(str_contains(Blade::render('<x-nexo-account-menu />'), 'name="_token"'))->toBeTrue('The sign-out form has no CSRF token.');
});

it('renders nothing for a guest', function () {
    expect(trim(Blade::render('<x-nexo-account-menu />')))->toBe('');
});

it('keeps «Cerrar sesión» inside the chrome menus only', function () {
    accountMenuSignIn();

    // A loose sign-out button in a layout is exactly the v1 drift (two apps had
    // one in the header, one at the foot of a card). Every logout form of the
    // panel page must be the account menu's or the phone sheet's.
    $html = test()->followingRedirects()->get(NexoChrome::panelUrl())->getContent();
    $logout = NexoChrome::logoutUrl();
    expect($logout)->not->toBeNull();

    $forms = substr_count($html, 'action="'.$logout.'"');
    $chrome = substr_count($html, 'data-nexo-account-logout') + substr_count($html, 'data-nexo-sheet-logout');

    expect($chrome)->toBe(2, 'The panel header must carry the account menu and the phone sheet, each with its sign-out.');
    expect($forms)->toBe($chrome, 'There is a sign-out form outside the account menu / phone sheet.');
});
