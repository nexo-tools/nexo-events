<?php

// Guardian: brand colors come from nexo-brand tokens (var(--nexo-*)), never raw
// hex in Blade views or app CSS. Adjust $allowed for files that legitimately hold
// literal colors (the generated tokens file, and <meta>/<input> values that can't
// reference a CSS var). SVGs under public/ are not scanned.

use Illuminate\Support\Str;
use RecursiveDirectoryIterator as Dir;
use RecursiveIteratorIterator as Walk;

it('has no hardcoded hex colors in blade views or app css (use --nexo-* tokens)', function () {
    $roots = array_filter([resource_path('views'), resource_path('css')], 'is_dir');

    // Relative paths (from resource_path) allowed to contain literal hex:
    //  - the generated brand tokens (the one place raw palette hex lives);
    //  - head: the <meta name="theme-color"> content can't be a CSS var;
    //  - emails: mail clients strip <style> and know nothing about our CSS
    //    variables, so transactional templates must inline literal hex.
    $allowed = [
        'css/nexo-tokens.css',
        'css/nexo-ui.css',
        'views/partials/head.blade.php',
        // <meta name="theme-color"> cannot reference a CSS variable either.
        'views/components/nexo-seo.blade.php',
    ];

    // Whole directories that legitimately inline literal colors.
    $allowedPrefixes = ['views/emails/'];

    $base = resource_path().DIRECTORY_SEPARATOR;
    $offenders = [];

    foreach ($roots as $root) {
        foreach (new Walk(new Dir($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! preg_match('/\.(blade\.php|css)$/', $file->getFilename())) {
                continue;
            }

            $rel = str_replace([$base, DIRECTORY_SEPARATOR], ['', '/'], $file->getPathname());

            if (in_array($rel, $allowed, true) || Str::startsWith($rel, $allowedPrefixes)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $contents, $m)) {
                $offenders[] = $rel.' -> '.implode(', ', array_unique($m[0]));
            }
        }
    }

    expect($offenders)->toBe([], "Hardcoded hex colors found (use var(--nexo-*)):\n".implode("\n", $offenders));
});
