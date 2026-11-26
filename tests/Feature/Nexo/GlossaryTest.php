<?php

// Guardian (chrome v2, 2026-11-26): one word per concept across the family
// (STANDARD «Glosario»):
//
//   Cupo (never «Aforo») · Cerrar sesión (never «Salir»/«Logout») ·
//   Iniciar sesión · Crear cuenta · Tu cuenta Nexo ID · Centro de ayuda ·
//   link corto (never «slug» in the UI) · panel
//
// Why it exists: the same concept had two names inside one app («Cupo» on one
// screen and «Aforo» on the next in Events, H-nexoevents-14) and a technical
// one in another («slug» in Short's form, H-nexoshort-08). A person crossing
// tools in one click cannot learn a vocabulary that changes on the way.
//
// What is scanned: everything a person reads — the translations (lang/*.json
// and lang/<locale>/*.php, values; the English source strings are the JSON
// keys) and the literal text of the Blade views (tags, attributes, PHP and Blade
// expressions stripped). Copy into tests/Feature/Nexo/. To extend the glossary,
// extend GLOSSARY_FORBIDDEN here AND in the template — never per app.

use RecursiveDirectoryIterator as GlossaryDir;
use RecursiveIteratorIterator as GlossaryWalk;

/** locale => [forbidden regex => what to say instead]. */
const GLOSSARY_FORBIDDEN = [
    'es' => [
        '/\baforo\b/iu' => 'Cupo',
        '/\bslugs?\b/iu' => 'link corto',
        '/\bSalir\b/u' => 'Cerrar sesión',
        '/\blog\s?out\b/iu' => 'Cerrar sesión',
    ],
    'en' => [
        '/\bslugs?\b/iu' => 'short link',
    ],
    'pt' => [
        '/\bslugs?\b/iu' => 'link curto',
    ],
];

/** Every translated string of a locale (and, for en, the JSON source keys). */
function glossaryStrings(string $locale): array
{
    $strings = [];

    $flatten = function ($value, string $path) use (&$flatten, &$strings): void {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $flatten($item, $path.'.'.$key);
            }
        } elseif (is_string($value)) {
            $strings[$path] = $value;
        }
    };

    foreach (glob(lang_path('*.json')) ?: [] as $json) {
        $map = json_decode((string) file_get_contents($json), true) ?: [];
        $file = basename($json, '.json');
        if ($file === $locale) {
            $flatten($map, "lang/{$file}.json");
        }
        if ($locale === 'en') {
            foreach (array_keys($map) as $source) {
                $strings["lang/{$file}.json (source)#".$source] = (string) $source;
            }
        }
    }

    foreach (glob(lang_path($locale.'/*.php')) ?: [] as $php) {
        // validation.php is framework boilerplate keyed by rule names.
        if (basename($php) === 'validation.php') {
            continue;
        }
        $flatten(require $php, 'lang/'.$locale.'/'.basename($php));
    }

    return $strings;
}

/** The literal text a Blade view renders (no tags, attributes, PHP or Blade). */
function glossaryViewText(string $blade): string
{
    $text = preg_replace([
        '/\{\{--.*?--\}\}/s',          // Blade comments
        '/@php\b.*?@endphp/s',          // @php blocks
        '/<\?php.*?\?>/s',              // raw PHP
        '/\{\{.*?\}\}/s',               // {{ echo }}
        '/\{!!.*?!!\}/s',               // {!! raw echo !!}
        '/<(script|style)\b.*?<\/\1>/s', // inline code
        '/<[^>]*>/s',                   // tags with their attributes
        '/@\w+(\s*\((?:[^()]|\([^()]*\))*\))?/', // directives and their arguments
    ], ' ', $blade);

    return (string) $text;
}

it('uses one word per concept in every translation', function () {
    $offenders = [];

    foreach (GLOSSARY_FORBIDDEN as $locale => $rules) {
        foreach (glossaryStrings($locale) as $where => $string) {
            foreach ($rules as $pattern => $instead) {
                if (preg_match($pattern, $string)) {
                    $offenders[] = "{$where}: «{$string}» → use «{$instead}»";
                }
            }
        }
    }

    expect($offenders)->toBe([], "Glossary violations (STANDARD «Glosario»):\n".implode("\n", $offenders));
});

it('uses one word per concept in the literal text of the views', function () {
    $rules = array_merge(...array_values(GLOSSARY_FORBIDDEN));
    $offenders = [];

    foreach (new GlossaryWalk(new GlossaryDir(resource_path('views'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php') || str_contains($file->getPathname(), '/views/emails/nexo/')) {
            continue;
        }

        $text = glossaryViewText((string) file_get_contents($file->getPathname()));
        foreach ($rules as $pattern => $instead) {
            if (preg_match($pattern, $text, $m)) {
                $offenders[] = str_replace(resource_path('views').'/', '', $file->getPathname())." → «{$m[0]}» (use «{$instead}»)";
            }
        }
    }

    expect($offenders)->toBe([], "Glossary violations in view text:\n".implode("\n", $offenders));
});

it('names the shared concepts with the glossary words', function () {
    $expected = [
        'nexo.account.sign_in' => 'Iniciar sesión',
        'nexo.account.sign_up' => 'Crear cuenta',
        'nexo.account.sign_out' => 'Cerrar sesión',
        'nexo.account.nexo_id' => 'Tu cuenta Nexo ID',
        'nexo.help.title' => 'Centro de ayuda',
    ];

    foreach ($expected as $key => $word) {
        expect(__($key, [], 'es'))->toBe($word, "{$key} must read «{$word}» in Spanish.");
    }

    // The same concepts as English source strings, where the app uses them.
    $json = json_decode((string) @file_get_contents(lang_path('es.json')), true) ?: [];
    foreach (['Sign in' => 'Iniciar sesión', 'Sign out' => 'Cerrar sesión', 'Create account' => 'Crear cuenta'] as $source => $word) {
        if (isset($json[$source])) {
            expect($json[$source])->toBe($word, "«{$source}» must translate to «{$word}».");
        }
    }
});
