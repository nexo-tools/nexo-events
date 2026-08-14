<?php

use RecursiveDirectoryIterator as Dir;
use RecursiveIteratorIterator as Walk;

/**
 * The project's rule is a SPEC before the code with AC↔test traceability, and
 * until now that check was a grep somebody remembered to run. Here it is a
 * test: every AC-API-n declared in the SPEC has to be named by at least one
 * test, and no test may claim an AC the SPEC does not have.
 *
 * The second half is the one that catches real drift — an AC gets renumbered
 * or dropped in a doc edit and the test that used to cover it keeps passing
 * under a name that means nothing.
 */
function specAcceptanceCriteria(): array
{
    $spec = (string) file_get_contents(base_path('docs/specs/SPEC-integration-api.md'));

    preg_match_all('/\*\*(AC-API-[0-9a-z]+)\*\*/', $spec, $matches);

    return array_values(array_unique($matches[1]));
}

function apiTestSources(): string
{
    $sources = '';

    foreach (new Walk(new Dir(base_path('tests'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.php')) {
            $sources .= (string) file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

it('names every acceptance criterion of the SPEC in at least one test', function (): void {
    $criteria = specAcceptanceCriteria();

    expect($criteria)->not->toBeEmpty('No AC-API-n found in the SPEC — did the format change?');

    $sources = apiTestSources();

    // The lookahead keeps AC-API-1 from being "covered" by a test that only
    // mentions AC-API-15.
    $uncovered = array_values(array_filter(
        $criteria,
        fn (string $ac): bool => preg_match('/'.preg_quote($ac, '/').'(?![0-9a-z])/i', $sources) !== 1
    ));

    expect($uncovered)->toBe([], "Acceptance criteria with no test naming them:\n".implode("\n", $uncovered));
});

it('claims no acceptance criterion the SPEC does not declare', function (): void {
    $criteria = specAcceptanceCriteria();

    // A digit is required, so the placeholder "AC-API-n" written in prose (in
    // this very file, among others) is not read as a claim.
    preg_match_all('/AC-API-\d[0-9a-z]*/i', apiTestSources(), $matches);

    $orphans = array_values(array_unique(array_filter(
        $matches[0],
        fn (string $ac): bool => ! in_array(strtoupper($ac), array_map('strtoupper', $criteria), true)
    )));

    expect($orphans)->toBe([], "Tests naming acceptance criteria that are not in the SPEC:\n".implode("\n", $orphans));
});
