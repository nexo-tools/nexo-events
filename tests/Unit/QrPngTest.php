<?php

use App\Services\QrPng;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

/**
 * Correctness of the emailed QR is proven against the encoder matrix rather
 * than by decoding: every module of the rendered PNG must match the module the
 * encoder produced. A QR that renders but encodes the wrong bits would be
 * invisible in a "does it look like a QR" assertion and would only surface at
 * a venue door.
 */
it('AC-EMAIL-3: renders every encoder module into the PNG, so the image encodes exactly the given token', function (): void {
    $token = str_repeat('a1B2', 10); // 40 chars, same shape as a real ticket token
    $moduleSize = 6;
    $margin = 4;

    $png = (new QrPng)->forText($token, $moduleSize, $margin);

    $image = imagecreatefromstring($png);
    expect($image)->not->toBeFalse();

    $matrix = Encoder::encode($token, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ENCODING)->getMatrix();
    $modules = $matrix->getWidth();

    expect(imagesx($image))->toBe(($modules + 2 * $margin) * $moduleSize)
        ->and(imagesy($image))->toBe(($modules + 2 * $margin) * $moduleSize);

    $mismatches = 0;
    for ($y = 0; $y < $modules; $y++) {
        for ($x = 0; $x < $modules; $x++) {
            // Sample the centre of the module to stay clear of any edge rounding.
            $px = ($x + $margin) * $moduleSize + intdiv($moduleSize, 2);
            $py = ($y + $margin) * $moduleSize + intdiv($moduleSize, 2);
            $isDark = imagecolorat($image, $px, $py) === 0;

            if ($isDark !== ($matrix->get($x, $y) === 1)) {
                $mismatches++;
            }
        }
    }

    expect($mismatches)->toBe(0);
});

it('AC-EMAIL-3: surrounds the code with the quiet zone scanners need', function (): void {
    $png = (new QrPng)->forText('quiet-zone-check', 4, 4);

    $image = imagecreatefromstring($png);
    expect($image)->not->toBeFalse();

    // The four corners sit inside the margin: all must be white.
    $side = imagesx($image);
    foreach ([[0, 0], [$side - 1, 0], [0, $side - 1], [$side - 1, $side - 1]] as [$x, $y]) {
        expect(imagecolorat($image, $x, $y))->toBe(0xFFFFFF);
    }
});

it('AC-EMAIL-3: emits a real PNG, not another format with a png name', function (): void {
    $png = (new QrPng)->forText('signature-check');

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});
