<?php

// Guardian (nexo-ui v2.1, 2026-11-26): no inline event handlers in the views
// (STANDARD «Sin handlers inline»).
//
// Why it exists: the CSP of every Nexo app allows scripts by hash and never
// 'unsafe-inline', so the browser drops an onsubmit=/onclick=/onchange=… without
// a word. In Nexo Events four `onsubmit="return confirm(…)"` («Cancelar evento»,
// «Liberar el cupo», «Quitar acceso», «Revocar») submitted without asking
// anything (CONTRATO-F3 §0.1, v2.1 item 5). A confirmation goes in
// `data-confirm="…"` (handled by nexo-ui.js) and any other behaviour in the
// app's JS or in Alpine (`@submit`, `x-on:click`), which the CSP allows.
//
// What is scanned: every file under resources/views, with Blade comments
// ({{-- --}}) and HTML comments (<!-- -->) blanked out first — they never reach
// the browser as attributes — keeping the line numbers. An attribute is
// `on<event>=` as a word of its own: `x-on:click=`, `@click=`, `data-onboarding=`
// and `el.onclick = …` are not attributes and do not count.
// Copy into tests/Feature/Nexo/ unchanged.

use RecursiveDirectoryIterator as InlineHandlersDir;
use RecursiveIteratorIterator as InlineHandlersWalk;

/** `on<event>=` as an attribute: not preceded by a word char, `:`, `.`, `@`, `-` or `$`. */
const INLINE_HANDLER_PATTERN = '/(?<![\w:.@$-])(on[a-z]{3,})\s*=(?!=)/i';

/** Blank out Blade and HTML comments, keeping every newline so line numbers hold. */
function inlineHandlersStripComments(string $source): string
{
    return (string) preg_replace_callback(
        '/\{\{--.*?--\}\}|<!--.*?-->/s',
        fn (array $match): string => preg_replace('/[^\n]/', ' ', $match[0]) ?? '',
        $source,
    );
}

/**
 * Every inline handler of a source, as "line N: onsubmit".
 *
 * @return list<string>
 */
function inlineHandlersIn(string $source): array
{
    $found = [];
    foreach (explode("\n", inlineHandlersStripComments($source)) as $index => $line) {
        if (preg_match_all(INLINE_HANDLER_PATTERN, $line, $matches) > 0) {
            foreach ($matches[1] as $attribute) {
                $found[] = 'line '.($index + 1).': '.strtolower($attribute);
            }
        }
    }

    return $found;
}

it('finds inline handlers and leaves Alpine, data attributes and comments alone', function () {
    $source = implode("\n", [
        '<form method="POST" onsubmit="return confirm(\'x\')">',
        '<button x-on:click="open = true" @click="open = !open" data-onboarding="1">',
        '{{-- not an inline onsubmit= in a Blade comment --}}',
        '<!-- nor an onclick= in an HTML comment -->',
        '<script>el.onclick = () => {}; if (a.onload == b) {}</script>',
        '<img src="x.png" ONERROR = "alert(1)">',
    ]);

    expect(inlineHandlersIn($source))->toBe(['line 1: onsubmit', 'line 6: onerror']);
});

it('has no inline event handler in resources/views (the CSP would drop it)', function () {
    $offenders = [];
    $views = resource_path('views');

    foreach (new InlineHandlersWalk(new InlineHandlersDir($views, InlineHandlersDir::SKIP_DOTS)) as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        $relative = 'resources/views/'.ltrim(substr($file->getPathname(), strlen($views)), '/');
        foreach (inlineHandlersIn((string) file_get_contents($file->getPathname())) as $hit) {
            $offenders[] = "{$relative} {$hit}";
        }
    }

    expect($offenders)->toBe([], "Inline event handlers (the CSP blocks them without a word). Use data-confirm=\"…\" for a confirmation, or the app's JS / Alpine:\n  ".implode("\n  ", $offenders));
});
