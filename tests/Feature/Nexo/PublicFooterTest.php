<?php

use App\Support\NexoChrome;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

// Guardian (chrome v2, 2026-11-26): the footer of the public CONTENT pages (D8 —
// a Links profile, an event page, a booking page). Those pages carry no tool
// header: whoever opens them visits a person or a business, not Nexo. What they
// keep is language, theme, «Hecho con Nexo X» and, only for the owner with a
// session, a discreet «Editar» chip (CONTRATO-F3 §5.3–5.4).
//
// The page decides who the owner is; this guards the component's side of the
// contract — the chip never shows to anybody without a session, and the
// controls a visitor needs are always there. Copy into tests/Feature/Nexo/
// (also in apps without content pages: the component ships identical in 6).

it('gives a visitor language, theme and «Hecho con» — and no chip', function () {
    $html = Blade::render('<x-nexo-public-footer edit-url="/editor" />');

    foreach (['es', 'en', 'pt'] as $code) {
        expect(str_contains($html, 'lang='.$code))->toBeTrue("The public footer does not offer [{$code}].");
    }
    expect(str_contains($html, 'data-nexo-theme-toggle'))->toBeTrue('The public footer has no theme toggle.');
    expect(str_contains($html, e(__('nexo.public_footer.made_with', ['app' => NexoChrome::brand()]))))->toBeTrue('The public footer does not say «Hecho con '.NexoChrome::brand().'».');
    expect(str_contains($html, 'data-nexo-edit-chip'))->toBeFalse('A visitor without a session sees «Editar».');
    expect(str_contains($html, 'nexo-header'))->toBeFalse('A public content page carries no tool header (D8).');
});

it('shows «Editar» only when the page passes an edit URL to a signed-in person', function () {
    $model = config('auth.providers.users.model');
    test()->actingAs($model::factory()->create());

    expect(str_contains(Blade::render('<x-nexo-public-footer />'), 'data-nexo-edit-chip'))
        ->toBeFalse('Without an edit-url (not the owner) there is no chip.');

    $html = Blade::render('<x-nexo-public-footer edit-url="/editor" />');
    expect(preg_match('/<a[^>]*href="\/editor"[^>]*data-nexo-edit-chip/s', $html))->toBe(1, 'The owner\'s chip must link to the editor.');
});

it('keeps Privacidad and Términos one tap away when the app has them', function () {
    // These pages take a visitor's own data (a registration, a booking), and
    // the full footer that linked the legal pages is gone from them (D8):
    // STANDARD «Páginas legales». Rendered only when the routes exist, so the
    // component stays the same in every app.
    $html = Blade::render('<x-nexo-public-footer />');

    foreach (['legal.privacy' => 'nexo.footer.privacy', 'legal.terms' => 'nexo.footer.terms'] as $route => $label) {
        if (! Route::has($route)) {
            continue;
        }

        expect(preg_match('/<a href="'.preg_quote(route($route), '/').'"[^>]*data-nexo-public-legal>'.preg_quote(e(__($label)), '/').'<\/a>/', $html))
            ->toBe(1, "The public footer must link [{$route}] («".__($label).'»).');
    }
});
