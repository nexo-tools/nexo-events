<?php

declare(strict_types=1);

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use RuntimeException;

/**
 * QR code as PNG bytes, for the ticket email.
 *
 * The on-screen ticket uses {@see QrSvg}; email cannot. Inline SVG is not
 * rendered by Outlook and data-URI images are stripped by Gmail (ADR-005 §7),
 * so the mail embeds a real PNG attachment referenced by Content-ID.
 *
 * Drawn with **GD on the encoder matrix** rather than through the library's
 * PNG writer, which only exists via `ImagickImageBackEnd`: Imagick is a PECL
 * extension and production is shared hosting, so it cannot be assumed present
 * (the dev container having it proves nothing about the host). GD ships with
 * virtually every PHP build. A missing image renderer must never be the reason
 * a ticket fails to reach someone.
 */
final class QrPng
{
    /**
     * @param  int  $moduleSize  pixels per QR module
     * @param  int  $marginModules  quiet zone, in modules (4 is the spec minimum)
     * @return string raw PNG bytes
     */
    public function forText(string $text, int $moduleSize = 8, int $marginModules = 4): string
    {
        $matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ENCODING)->getMatrix();
        $modules = $matrix->getWidth();
        $side = ($modules + 2 * $marginModules) * $moduleSize;

        $image = imagecreatetruecolor($side, $side);
        if ($image === false) {
            throw new RuntimeException('Could not allocate the QR image (is ext-gd installed?).');
        }

        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $black = (int) imagecolorallocate($image, 0, 0, 0);
        imagefilledrectangle($image, 0, 0, $side - 1, $side - 1, $white);

        for ($y = 0; $y < $modules; $y++) {
            for ($x = 0; $x < $modules; $x++) {
                if ($matrix->get($x, $y) !== 1) {
                    continue;
                }

                $left = ($x + $marginModules) * $moduleSize;
                $top = ($y + $marginModules) * $moduleSize;
                imagefilledrectangle($image, $left, $top, $left + $moduleSize - 1, $top + $moduleSize - 1, $black);
            }
        }

        ob_start();
        imagepng($image);

        // No imagedestroy(): GdImage has been garbage-collected since PHP 8.0
        // and the call is deprecated as of 8.5.
        return (string) ob_get_clean();
    }
}
