<?php

use Illuminate\Support\Facades\Process;

it('AC-I18N-2: generated translation files stay in sync with source strings', function () {
    $node = trim((string) (Process::run('command -v node')->output()));

    if ($node === '') {
        $this->markTestSkipped('node is not available in this environment (CI runs the generator step directly).');
    }

    $result = Process::path(base_path())->run('node scripts/generate-translations.mjs --check');

    expect($result->successful())->toBeTrue($result->errorOutput() ?: $result->output());
});
