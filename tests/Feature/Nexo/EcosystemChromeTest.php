<?php

use App\Support\NexoChrome;
use Illuminate\Support\Facades\Blade;

// Guardian: the shared chrome is present and wired. Copy into tests/Feature/Nexo/.
// Covers: the ecosystem registry exists (with an entry per tool), the
// app-switcher renders it (with the hub invite, translated descriptions and the
// v2 links), and the footer renders the canonical attribution (set + unset),
// translated but never prefixed.
//
// v2 (2026-11-26, chrome v2): switcher links depend on the session, the
// descriptions come from nexo.ecosystem.<key>, and «powered by» is translated.

it('exposes the ecosystem registry, with an entry path per tool', function () {
    expect(config('nexo-ecosystem.tools'))->toBeArray()->not->toBeEmpty();
    expect(config('nexo-ecosystem.hub_url'))->toBeString()->not->toBeEmpty();

    foreach (config('nexo-ecosystem.tools') as $key => $tool) {
        expect(str_starts_with((string) ($tool['entry'] ?? ''), '/'))
            ->toBeTrue("Registry entry [{$key}] has no `entry` path (chrome v2: the switcher opens the panel signed in).");
    }
});

it('renders the app-switcher with every tool and the hub invite', function () {
    $html = Blade::render('<x-nexo-app-switcher />');

    expect($html)->toContain('nexo-menu');
    foreach (config('nexo-ecosystem.tools') as $tool) {
        expect($html)->toContain($tool['name']);
    }
    // The standing bridge to the hub is always present.
    expect($html)->toContain(config('nexo-ecosystem.hub_url'))
        ->and($html)->toContain(config('nexo-ecosystem.github_org_url'));
});

it('translates every tool description in every locale', function () {
    foreach (array_keys(config('nexo-ecosystem.tools')) as $key) {
        foreach (['es', 'en', 'pt'] as $locale) {
            expect(trans()->has("nexo.ecosystem.{$key}", $locale, false))
                ->toBeTrue("lang/{$locale}/nexo.php has no ecosystem.{$key} — the switcher would show it in Spanish.");
        }
    }

    app()->setLocale('en');
    $html = Blade::render('<x-nexo-app-switcher />');
    foreach (array_keys(config('nexo-ecosystem.tools')) as $key) {
        expect(str_contains($html, e(__("nexo.ecosystem.{$key}"))))->toBeTrue("The switcher does not show the English description of [{$key}].");
    }
});

it('links each tool\'s landing for a guest and its signed-in entry with a session', function () {
    config()->set('nexo-sso.enabled', true);
    $tools = config('nexo-ecosystem.tools');

    $guest = Blade::render('<x-nexo-app-switcher />');
    foreach ($tools as $key => $tool) {
        expect(str_contains($guest, 'href="'.rtrim($tool['url'], '/').'"'))->toBeTrue("A guest's switcher must link the landing of [{$key}].");
    }

    $model = config('auth.providers.users.model');
    test()->actingAs($model::factory()->create());
    $signedIn = Blade::render('<x-nexo-app-switcher />');

    foreach ($tools as $key => $tool) {
        $href = $key === NexoChrome::current() ? NexoChrome::panelUrl() : rtrim($tool['url'], '/').$tool['entry'];
        expect(str_contains($signedIn, 'href="'.$href.'"'))->toBeTrue("With a session the switcher must open [{$key}] at {$href} (0 clicks to its panel).");
    }

    // Standalone (no SSO) there is no shared session to ride: landings again.
    // Nexo ID has no SSO client — its own session is the shared one.
    if (NexoChrome::current() === 'nexoid') {
        return;
    }
    config()->set('nexo-sso.enabled', false);
    $standalone = Blade::render('<x-nexo-app-switcher />');
    foreach ($tools as $key => $tool) {
        expect(str_contains($standalone, 'href="'.rtrim($tool['url'], '/').'"'))->toBeTrue("Standalone, the switcher links the landing of [{$key}].");
    }
});

it('renders the attribution label as the whole phrase, translated, with nothing prepended', function () {
    // The label IS the whole phrase. A tool that prepends its own prefix ships
    // "Made by powered by example.test" — the old assertion used a bare domain,
    // which reads fine either way and hid exactly that bug in production for
    // months, so assert the rendered anchor text. Since v2 the two phrase
    // shapes in use («powered by X», «made with X») are translated with X intact.
    config()->set('nexo.attribution.label', 'powered by example.test');
    config()->set('nexo.attribution.url', 'https://example.test');

    $anchor = function (string $html): ?string {
        return preg_match('/<a[^>]*href="https:\/\/example\.test"[^>]*>\s*(.*?)\s*<\/a>/s', $html, $m) ? $m[1] : null;
    };

    app()->setLocale('en');
    expect($anchor(Blade::render('<x-nexo-footer />')))->toBe('powered by example.test', 'The attribution anchor must contain exactly the label.');

    foreach (['es', 'pt'] as $locale) {
        app()->setLocale($locale);
        $text = $anchor(Blade::render('<x-nexo-footer />'));
        expect($text)->toBe(__('nexo.footer.powered_by', ['name' => 'example.test']), "«powered by» is not translated in [{$locale}].");
        expect(str_contains((string) $text, 'powered by'))->toBeFalse("The {$locale} footer still says «powered by».");
    }

    // A phrase of another shape is shown verbatim, in any language.
    config()->set('nexo.attribution.label', 'Example Studio');
    expect($anchor(Blade::render('<x-nexo-footer />')))->toBe('Example Studio');

    // Belt and braces: no tool may reintroduce a prefix key.
    $html = Blade::render('<x-nexo-footer />');
    foreach (['Hecho por', 'Made by', 'Feito por'] as $prefix) {
        expect(str_contains($html, $prefix))
            ->toBeFalse("The footer prepends \"{$prefix}\" to the attribution label.");
    }
});

it('falls back to the product label, never the upstream author, when unset', function () {
    // A third-party instance that sets no attribution must not end up
    // advertising the upstream author's site (add-branding-footer: open-source
    // multi-instance products carry a neutral product default). Both keys are
    // nulled explicitly so the assertion does not depend on whoever runs it
    // having NEXO_ATTRIBUTION_* in their local .env.
    config()->set('nexo.attribution.label', null);
    config()->set('nexo.attribution.url', null);

    app()->setLocale('en');
    $html = Blade::render('<x-nexo-footer />');

    expect(str_contains($html, 'made with '.NexoChrome::brand()))->toBeTrue('The footer does not credit the product.');
    expect(str_contains($html, 'href="https://alvarocdev.com"'))->toBeFalse('The footer is advertising the upstream author.');
});
