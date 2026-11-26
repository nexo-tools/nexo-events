<?php

use App\Support\NexoChrome;
use Illuminate\Support\Facades\Route;

// Guardian (chrome v2, 2026-11-26): nothing that carries a session is kept by
// the browser — `Cache-Control: no-store` on every response to a signed-in
// request, and on the auth screens (CONTRATO-F3 §3, STANDARD «no-store»).
//
// Why it exists: after «Cerrar sesión», the back button showed the Nexo ID
// profile with name and email, and the Events and Links panels with their data,
// straight from the browser cache (H-cruce-03, verified 2026-11-24). Those pages
// were served `no-cache, private` — which allows storing them.
//
// Implementation: templates/nexo-ui/middleware/NexoNoStore.php, appended to
// the `web` group. Copy this file into tests/Feature/Nexo/. Nothing to adapt.

function noStoreAssert($response, string $page): void
{
    $cache = (string) $response->headers->get('Cache-Control');

    expect(str_contains($cache, 'no-store'))->toBeTrue("[{$page}] answered Cache-Control: \"{$cache}\" — a page with a session must be no-store.");
}

it('marks every signed-in page no-store', function () {
    $model = config('auth.providers.users.model');
    test()->actingAs($model::factory()->create());

    $pages = ['home' => url('/'), 'panel' => NexoChrome::panelUrl()];
    foreach (['help', 'legal.privacy'] as $name) {
        if (Route::has($name)) {
            $pages[$name] = route($name);
        }
    }
    $pages['404'] = url('/nexo-no-store/'.uniqid());

    foreach ($pages as $page => $url) {
        noStoreAssert(test()->get($url), $page);
    }
});

it('marks the auth screens no-store, signed in or not', function () {
    $checked = 0;

    foreach (['login', 'register', 'password.request', 'nexo-sso.signed-out'] as $name) {
        if (! Route::has($name)) {
            continue;
        }

        $response = test()->get(route($name));
        if ($response->status() === 404) {
            continue; // a mode that turns the screen off (NEXO_AUTH_MODE=sso)
        }

        noStoreAssert($response, $name);
        $checked++;
    }

    expect($checked)->toBeGreaterThan(0, 'None of the auth screens rendered — nothing was checked.');
});
