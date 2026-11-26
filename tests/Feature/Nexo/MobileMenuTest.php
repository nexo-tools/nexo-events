<?php

use App\Support\NexoChrome;
use Illuminate\Support\Facades\Blade;

// Guardian (chrome v2, 2026-11-26): on a phone the header is the wordmark, the
// switcher and ONE button that opens a full-width sheet with everything else
// (D6, CONTRATO-F3 §5.1):
//
//   signed in   identity · own nav · Tu panel · Tu cuenta Nexo ID · Centro de
//               ayuda · Idioma · Tema · Cerrar sesión
//   guest       Iniciar sesión · Crear cuenta · Centro de ayuda · Idioma · Tema
//
// Why it exists: before v2 there was no phone menu in any of the 6 apps.
// «Iniciar sesión» and the help link were hidden under 640px, and «Cerrar
// sesión» was clipped or off-screen at 320px in four of them (audit 2026-11-24).
//
// What this file can see is the markup and the wiring; whether it FITS 320px is
// a browser question (skills/usability-review: inventory() at 320/390/1280 with
// horizontalOverflow false and no clippedControls/menusOutOfView).
//
// Copy into tests/Feature/Nexo/. Nothing to adapt.

/** Position of a marker in the sheet html, failing loudly when it is missing. */
function mobileMenuPosition(string $html, string $marker): int
{
    $position = strpos($html, $marker);
    expect($position)->not->toBeFalse("The phone sheet does not render [{$marker}].");

    return (int) $position;
}

function mobileMenuAssertOrder(array $positions, string $what): void
{
    $sorted = $positions;
    sort($sorted);
    expect($positions)->toBe($sorted, "The phone sheet is out of the canonical order ({$what}).");
}

it('opens a modal sheet from a single labelled button', function () {
    $html = Blade::render('<x-nexo-mobile-menu />');

    expect(preg_match('/<button[^>]*aria-haspopup="dialog"[^>]*>/s', $html, $trigger))->toBe(1, 'The phone trigger must declare aria-haspopup="dialog".');
    expect(str_contains($trigger[0], 'aria-expanded'))->toBeTrue('The phone trigger does not expose aria-expanded.');
    expect(preg_match('/aria-controls="([^"]+)"/', $trigger[0], $controls))->toBe(1, 'The phone trigger does not name the sheet it opens (aria-controls).');
    expect(str_contains($trigger[0], 'aria-label='))->toBeTrue('The phone trigger is an icon: it needs an aria-label.');

    expect(preg_match('/<div[^>]*id="'.preg_quote($controls[1], '/').'"[^>]*>/s', $html, $sheet))->toBe(1, 'aria-controls points at nothing.');
    expect(str_contains($sheet[0], 'role="dialog"'))->toBeTrue('The sheet must be role="dialog".');
    expect(str_contains($sheet[0], 'aria-modal="true"'))->toBeTrue('The sheet must be aria-modal="true" (it covers the page).');
    expect(str_contains($sheet[0], 'aria-label='))->toBeTrue('The sheet needs an accessible name.');

    expect(str_contains($html, 'nexoMenu({ modal: true })'))->toBeTrue('The sheet must reuse nexoMenu in modal mode (focus trap + Escape).');
    expect(str_contains($html, 'data-nexo-sheet-close'))->toBeTrue('The sheet needs its own close button.');
});

it('puts the whole account in the sheet, in order, for a signed-in person', function () {
    config()->set('nexo-sso.enabled', true);
    config()->set('nexo-sso.issuer', 'https://id.example.test');
    $model = config('auth.providers.users.model');
    test()->actingAs($model::factory()->create(['name' => 'Ana Vidal', 'email' => 'ana.vidal@example.test']));

    $html = Blade::render('<x-nexo-mobile-menu />');

    $positions = [mobileMenuPosition($html, 'data-nexo-sheet-identity'), mobileMenuPosition($html, 'data-nexo-sheet-panel')];
    if (NexoChrome::current() !== 'nexoid') {
        $positions[] = mobileMenuPosition($html, 'data-nexo-sheet-nexoid');
    }
    array_push(
        $positions,
        mobileMenuPosition($html, 'data-nexo-sheet-help'),
        mobileMenuPosition($html, 'data-nexo-sheet-language'),
        mobileMenuPosition($html, 'data-nexo-sheet-theme'),
        mobileMenuPosition($html, 'data-nexo-sheet-logout'),
    );
    mobileMenuAssertOrder($positions, 'identity, panel, Nexo ID, help, language, theme, sign out');

    expect(str_contains($html, 'ana.vidal@example.test'))->toBeTrue('The sheet does not show who is signed in.');
    expect(str_contains($html, 'data-nexo-sheet-sign-in'))->toBeFalse('A signed-in sheet offers «Iniciar sesión».');
});

it('offers sign in, sign up, help, language and theme to a guest', function () {
    $html = Blade::render('<x-nexo-mobile-menu />');

    $positions = [mobileMenuPosition($html, 'data-nexo-sheet-sign-in')];
    if (NexoChrome::signUpUrl() !== null) {
        $positions[] = mobileMenuPosition($html, 'data-nexo-sheet-sign-up');
    }
    array_push(
        $positions,
        mobileMenuPosition($html, 'data-nexo-sheet-help'),
        mobileMenuPosition($html, 'data-nexo-sheet-language'),
        mobileMenuPosition($html, 'data-nexo-sheet-theme'),
    );
    mobileMenuAssertOrder($positions, 'sign in, sign up, help, language, theme');

    expect(str_contains($html, 'href="'.NexoChrome::signInUrl().'"'))->toBeTrue('«Iniciar sesión» in the sheet does not go where the header\'s does.');
    expect(str_contains($html, 'data-nexo-sheet-logout'))->toBeFalse('A guest sheet offers «Cerrar sesión».');
});

it('lists every language and the theme toggle inside the sheet', function () {
    $html = Blade::render('<x-nexo-mobile-menu />');

    foreach (['es', 'en', 'pt'] as $code) {
        expect(str_contains($html, 'lang='.$code))->toBeTrue("The sheet does not offer the [{$code}] language.");
    }
    expect(str_contains($html, 'data-nexo-locale-inline'))->toBeTrue('Inside the sheet the languages are a plain row, not a nested dropdown.');
    expect(str_contains($html, 'data-nexo-theme-toggle'))->toBeTrue('The sheet has no theme toggle.');
});

it('moves the app\'s own nav into the sheet', function () {
    $model = config('auth.providers.users.model');
    test()->actingAs($model::factory()->create());

    $html = Blade::render('<x-nexo-header><x-slot:nav><a href="/own-nav-link">Own nav link</a></x-slot:nav></x-nexo-header>');

    expect(substr_count($html, 'href="/own-nav-link"'))->toBe(2, 'The own nav must render in the desktop header AND in the phone sheet.');
    expect(str_contains($html, 'data-nexo-sheet-nav'))->toBeTrue();
});

it('hides the desktop controls under 768px and the sheet button above', function () {
    $css = (string) file_get_contents(resource_path('css/nexo-ui.css'));

    expect(preg_match('/\.nexo-header__desktop\s*\{[^}]*display:\s*none/', $css))->toBe(1, 'nexo-ui.css must hide .nexo-header__desktop on phones.');
    expect(preg_match('/@media \(min-width: 768px\)\s*\{[^}]*\.nexo-header__desktop[^}]*display:\s*flex[^}]*\}\s*\.nexo-header__mobile\s*\{\s*display:\s*none/s', $css))
        ->toBe(1, 'nexo-ui.css must swap the desktop group in and the sheet button out at 768px.');
    expect(preg_match('/\.nexo-sheet\s*\{[^}]*position:\s*fixed[^}]*inset:\s*0/s', $css))->toBe(1, 'The sheet must cover the screen edge to edge.');

    $js = (string) file_get_contents(resource_path('js/nexo-ui.js'));
    expect(str_contains($js, 'modal') && str_contains($js, "event.key !== 'Tab'"))->toBeTrue('nexo-ui.js has no focus trap for the modal sheet.');
});
