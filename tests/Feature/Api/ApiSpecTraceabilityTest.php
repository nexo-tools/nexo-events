<?php

use RecursiveDirectoryIterator as Dir;
use RecursiveIteratorIterator as Walk;

/**
 * The project's rule is a SPEC before the code with AC↔test traceability, and
 * until now that check was a grep somebody remembered to run. Here it is a
 * test: every criterion declared in a SPEC has to be named by at least one
 * test, and no test may claim a criterion its SPEC does not have.
 *
 * The second half is the one that catches real drift — an AC gets renumbered
 * or dropped in a doc edit and the test that used to cover it keeps passing
 * under a name that means nothing.
 *
 * It started with the integration API alone. Since 10.9 it reads every SPEC
 * listed in traceableSpecs(), each with its own prefix; the rules are the same
 * for all of them. A criterion is declared by writing it in bold
 * (`**AC-COLLAB-n**`): criteria planned for a later task are written without
 * bold, so a SPEC can be written before its code without the suite going red
 * and without this guardian being loosened to allow it.
 */

/** @return array<string, string> SPEC path => AC prefix */
function traceableSpecs(): array
{
    return [
        'docs/specs/SPEC-integration-api.md' => 'AC-API-',
        'docs/specs/SPEC-event-collaborators.md' => 'AC-COLLAB-',
        'docs/specs/SPEC-ticket-info.md' => 'AC-TICKET-',
        'docs/specs/SPEC-event-panel.md' => 'AC-PANEL-',
    ];
}

/** @return array<int, string> */
function specAcceptanceCriteria(string $spec, string $prefix): array
{
    $text = (string) file_get_contents(base_path($spec));

    preg_match_all('/\*\*('.preg_quote($prefix, '/').'[0-9a-z]+)\*\*/', $text, $matches);

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

dataset('traceable specs', fn (): array => collect(traceableSpecs())
    ->map(fn (string $prefix, string $spec): array => [$spec, $prefix])
    ->values()
    ->all());

it('names every acceptance criterion of the SPEC in at least one test', function (string $spec, string $prefix): void {
    $criteria = specAcceptanceCriteria($spec, $prefix);

    expect($criteria)->not->toBeEmpty("No {$prefix}n found in {$spec} — did the format change?");

    $sources = apiTestSources();

    // The lookahead keeps AC-API-1 from being "covered" by a test that only
    // mentions AC-API-15.
    $uncovered = array_values(array_filter(
        $criteria,
        fn (string $ac): bool => preg_match('/'.preg_quote($ac, '/').'(?![0-9a-z])/i', $sources) !== 1
    ));

    expect($uncovered)->toBe([], "Acceptance criteria of {$spec} with no test naming them:\n".implode("\n", $uncovered));
})->with('traceable specs');

it('claims no acceptance criterion the SPEC does not declare', function (string $spec, string $prefix): void {
    $criteria = specAcceptanceCriteria($spec, $prefix);

    // A digit is required, so a placeholder like "AC-API-n" written in prose
    // (in this very file, among others) is not read as a claim.
    preg_match_all('/'.preg_quote($prefix, '/').'\d[0-9a-z]*/i', apiTestSources(), $matches);

    $orphans = array_values(array_unique(array_filter(
        $matches[0],
        fn (string $ac): bool => ! in_array(strtoupper($ac), array_map('strtoupper', $criteria), true)
    )));

    expect($orphans)->toBe([], "Tests naming acceptance criteria that are not in {$spec}:\n".implode("\n", $orphans));
})->with('traceable specs');
