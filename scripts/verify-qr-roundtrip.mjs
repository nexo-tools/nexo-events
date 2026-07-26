// Decode a QR PNG with the very library the scanner ships (jsQR) and print the
// payload. Used by QrRoundTripTest to prove that what the app *generates* is
// what the door will *read* — a QR can be a valid image and still be unreadable
// (wrong module size, missing quiet zone, inverted colors), and that failure
// only shows up at a venue.
//
// PNG decoding uses pngjs, not sharp: sharp ships a platform-specific native
// binary, and this runs from inside the Linux app container against a
// node_modules installed on the host. Pure JS keeps host, container and CI
// interchangeable.
//
// Usage: node scripts/verify-qr-roundtrip.mjs <path-to-png>
import { readFileSync } from 'node:fs'
import jsQR from 'jsqr'
import { PNG } from 'pngjs'

const file = process.argv[2]
if (!file) {
    console.error('usage: node scripts/verify-qr-roundtrip.mjs <path-to-png>')
    process.exit(2)
}

const png = PNG.sync.read(readFileSync(file))
const result = jsQR(new Uint8ClampedArray(png.data), png.width, png.height)

if (!result) {
    console.error('UNREADABLE')
    process.exit(1)
}

process.stdout.write(result.data)
