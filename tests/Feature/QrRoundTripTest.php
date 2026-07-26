<?php

use App\Services\QrPng;
use Illuminate\Support\Str;

/**
 * The QR the app generates must be readable by the decoder the scanner ships.
 *
 * QrPngTest already proves the PNG matches the encoder's matrix, but that is a
 * statement about our own two components agreeing with each other. This closes
 * the loop with the thing that actually matters at a door: jsQR — the exact
 * library bundled for the scanner — reading the image back and getting the same
 * token. A QR can be a perfectly valid image and still be unreadable in
 * practice (too few pixels per module, no quiet zone, inverted colors), and
 * that failure would otherwise surface for the first time at an event.
 *
 * Shells out to node, like TranslationsSyncTest; skipped where node is absent.
 */
it('AC-SCAN-1: a generated ticket QR decodes back to its exact token with the decoder the scanner ships', function (): void {
    if (! is_dir(base_path('node_modules/jsqr')) || ! is_dir(base_path('node_modules/pngjs'))) {
        $this->markTestSkipped('node_modules (jsqr, pngjs) not installed.');
    }

    $token = Str::random(40); // same shape as a real ticket token
    $png = tempnam(sys_get_temp_dir(), 'qr').'.png';
    file_put_contents($png, (new QrPng)->forText($token));

    $command = 'node '.escapeshellarg(base_path('scripts/verify-qr-roundtrip.mjs')).' '.escapeshellarg($png).' 2>&1';
    $decoded = trim((string) shell_exec($command));
    @unlink($png);

    if (str_contains($decoded, 'node: not found') || str_contains($decoded, 'command not found')) {
        $this->markTestSkipped('node is not available in this environment.');
    }

    expect($decoded)->toBe($token);
});
