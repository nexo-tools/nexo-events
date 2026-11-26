<?php

use App\Support\NexoChrome;
use Illuminate\Support\Facades\Route;

// Guardian (chrome v2, 2026-11-26): one tab-title format for the family
// (STANDARD «Títulos»):
//
//   landing      «Nexo <App> — <tagline>»
//   any other    «<Página> — Nexo <App>»
//
// Why it exists: the audit of 2026-11-24 found six formats in six apps —
// «Tu cuenta» (no brand), «NexoTools» (APP_NAME without the space, and no page),
// «Nexo Events» (no page), «… — Nexo Agenda», «… · Nexo Links» — so a row of
// tabs could not tell which page was which. The brand is the registry name
// (NexoChrome::brand()), never APP_NAME.
//
// Copy into tests/Feature/Nexo/. Pages come from route names every Nexo app
// shares; one that does not exist here, or does not render (a mode that turns
// a screen off), is skipped.

function pageTitleOf(string $html, string $page): string
{
    expect(preg_match_all('/<title>(.*?)<\/title>/s', $html, $m))->toBe(1, "[{$page}] must render exactly one <title>.");

    return trim(html_entity_decode($m[1][0], ENT_QUOTES));
}

function pageTitleAssertInner(string $title, string $page): void
{
    $brand = NexoChrome::brand();
    $suffix = ' — '.$brand;

    expect(str_ends_with($title, $suffix) && strlen($title) > strlen($suffix))
        ->toBeTrue("[{$page}] title is «{$title}»; it must read «<Página> — {$brand}».");
}

it('titles the landing «Nexo App — tagline»', function () {
    $brand = NexoChrome::brand();
    $title = pageTitleOf(test()->get('/')->getContent(), 'home');

    expect(str_starts_with($title, $brand.' — ') && strlen($title) > strlen($brand.' — '))
        ->toBeTrue("The landing title is «{$title}»; it must read «{$brand} — <tagline>».");
});

it('titles every public page «Página — Nexo App»', function () {
    $checked = 0;

    foreach (['help', 'legal.privacy', 'legal.terms', 'login', 'register', 'password.request', 'nexo-sso.signed-out'] as $name) {
        if (! Route::has($name)) {
            continue;
        }
        $response = test()->get(route($name));
        if ($response->status() !== 200) {
            continue;
        }

        pageTitleAssertInner(pageTitleOf($response->getContent(), $name), $name);
        $checked++;
    }

    pageTitleAssertInner(pageTitleOf(test()->get('/nexo-page-title/'.uniqid())->getContent(), '404'), '404');

    expect($checked)->toBeGreaterThan(0);
});

it('titles the panel «Página — Nexo App»', function () {
    $model = config('auth.providers.users.model');
    test()->actingAs($model::factory()->create());

    $response = test()->followingRedirects()->get(NexoChrome::panelUrl());

    pageTitleAssertInner(pageTitleOf($response->getContent(), 'panel'), 'panel');
});
