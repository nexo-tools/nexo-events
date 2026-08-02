<?php

use Illuminate\Support\Facades\Route;

// Guardian: the static surfaces every Nexo tool must have — error pages with the
// tool's identity, and legal pages — exist, answer, and are translated.
//
// Why it exists: 404/419/429/500 and privacy/terms are the pages nobody opens
// while building, so they rot silently. 419 and 429 in particular are the ones a
// real user hits (expired session, rate limit) and the ones most often left as
// Laravel's untranslated default.
//
// Copy into tests/Feature/Nexo/. If a tool legitimately lacks a page (an isolated
// short-link host only needs a minimal 404), drop it from $codes and record the
// exception in the tool's AGENTS.md.
//
// v2 (2026-08-02) also asserts what the error page WEARS: the tool chrome and a
// noindex. This tool served its errors as a bare document with no header, no
// footer and no toggles, and without a noindex on top of it.
//
// Pest note: toContain() is variadic — a second argument is another needle, not
// a failure message — so human-readable messages go through toBeTrue()/toBeFalse().

$codes = [403, 404, 419, 429, 500, 503];

// Hosts whose error pages are deliberately chrome-less (the isolated short-link
// host of nexoshort). This tool has none.
const ISOLATED_ERROR_HOSTS = [];

/** The URL whose 404 must carry the tool chrome. */
function chromeErrorUrl(string $path): string
{
    return $path;
}

it('ships an error view for every code the standard requires', function () use ($codes) {
    $missing = array_values(array_filter(
        $codes,
        fn (int $code) => ! is_file(resource_path("views/errors/{$code}.blade.php"))
    ));

    expect($missing)->toBe([], 'Missing error views (copy from templates/nexo-ui/pages/errors/): '.implode(', ', $missing));
});

it('renders error pages with the tool chrome and no untranslated placeholders', function () use ($codes) {
    foreach ($codes as $code) {
        $path = resource_path("views/errors/{$code}.blade.php");
        if (! is_file($path)) {
            continue;
        }

        $contents = (string) file_get_contents($path);

        // Strings go through __() so the generator can translate them.
        expect(str_contains($contents, '__('))->toBeTrue("errors/{$code}.blade.php has hardcoded strings — wrap them in __().");
        expect(str_contains($contents, '[COMPLETAR'))->toBeFalse("errors/{$code}.blade.php still has a template placeholder.");
    }
});

it('serves a branded 404 instead of the framework default', function () {
    $url = chromeErrorUrl('/this-path-does-not-exist-'.uniqid());

    $html = $this->get($url)
        ->assertNotFound()
        ->getContent();

    // The chrome renders, so the page belongs to the product.
    expect($html)->toContain('404');
    expect(str_contains($html, 'Whoops, looks like something went wrong'))->toBeFalse('Laravel default error page is still being served.');

    $host = parse_url($url, PHP_URL_HOST) ?: parse_url((string) config('app.url'), PHP_URL_HOST);
    if (in_array($host, ISOLATED_ERROR_HOSTS, true)) {
        return;
    }

    // Chrome, not just branding: an error page that drops the header drops the
    // theme toggle, the language switcher and the way back with it.
    expect(str_contains($html, 'nexo-header'))->toBeTrue('The 404 does not render x-nexo-header — use the canonical error-layout.');
    expect(str_contains($html, 'nexo-footer'))->toBeTrue('The 404 does not render x-nexo-footer — use the canonical error-layout.');

    // And it stays out of the index. Asserting the rendered meta (not the
    // include) also catches a head that quietly ignores the noindex flag.
    expect(preg_match('/<meta[^>]+name=["\']robots["\'][^>]+noindex/i', $html))
        ->toBe(1, 'The 404 is missing <meta name="robots" content="noindex">.');
});

it('serves the legal pages and links them from each other', function () {
    foreach (['legal.privacy', 'legal.terms'] as $route) {
        expect(Route::has($route))->toBeTrue("Route {$route} is not registered (see templates/nexo-ui/pages/legal/routes-snippet.php).");

        $html = $this->get(route($route))->assertOk()->getContent();

        expect(str_contains($html, '[COMPLETAR'))->toBeFalse("The {$route} page still ships a template placeholder — write this tool's real content before shipping.");
        expect($html)->toContain(route('legal.privacy'));
        expect($html)->toContain(route('legal.terms'));
    }
});

it('translates the legal content in every supported locale', function () {
    foreach (['es', 'en', 'pt'] as $locale) {
        $path = lang_path("{$locale}/legal.php");
        expect(is_file($path))->toBeTrue("lang/{$locale}/legal.php is missing.");

        $content = require $path;

        foreach (['privacy', 'terms'] as $page) {
            expect($content[$page]['title'] ?? null)->not->toBeNull("legal.{$page}.title missing in {$locale}.");
            expect($content[$page]['sections'] ?? [])->not->toBeEmpty("legal.{$page}.sections empty in {$locale}.");
        }

        expect(str_contains((string) json_encode($content), '[COMPLETAR'))->toBeFalse("lang/{$locale}/legal.php still has template placeholders.");
    }
});

it('names the instance operator on the legal pages when configured', function () {
    // The env-driven operator/contact contract (templates/nexo-ui/pages): with
    // the values set, the pages must say who answers for this instance. This
    // tool was the reference the contract was extracted FROM, predates it, and
    // shipped without the section — while the deploy pipeline claimed the envs
    // were enough. Nothing asserted the render, so nothing caught it.
    config()->set('nexo.legal.operator', 'Example Operator');
    config()->set('nexo.legal.contact', 'legal@example.test');

    $html = $this->get(route('legal.privacy'))->assertOk()->getContent();

    expect(str_contains($html, 'Example Operator'))->toBeTrue('The operator section did not render.');
    expect(str_contains($html, 'legal@example.test'))->toBeTrue('The contact did not render.');
});
