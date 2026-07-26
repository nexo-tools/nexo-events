# SPEC — Camera check-in at the door (PLAN Phase 6)

> Written before the code. Each AC states **how it is verified**, because this feature has a part no PHP test can reach: whether a real phone camera decodes a real ticket. Those ACs are settled by the device pass (task 6.5) and are marked *device*. Governing decisions: **ADR-002 §5**, **ADR-004 §6**, **ADR-007 §2**.

## Purpose

Close the second deferred MVP promise. Today `app/eventos/{event}/escanear` is a text input: the organizer reads the token off the attendee's screen and types it. That works, but it is not the product — "point your phone at the QR" is the reason the QR exists.

## Scope

**In:** on-device QR decoding from the rear camera on the existing scanner page; submitting decoded tokens to the check-in path that already exists; green/red result UX with reasons; keeping manual entry as the fallback; the rate limits that this hot path has been missing.

**Out:** staff/collaborator scanners (v2, ADR-004 §7). Offline check-in (v1 is online-only, ADR-002). Native apps. Scanning anything other than this instance's tickets.

## Design decisions

### Decode on-device, in JavaScript, with no new CSP holes

The scanner runs where the tickets are validated: the organizer's phone browser, over HTTPS (`getUserMedia` requires a secure context — already true in production, and `localhost` counts in development).

- **Native `BarcodeDetector` when the browser has it** (Android Chrome): hardware-backed, zero bytes shipped.
- **`jsQR` as the fallback** for everything else — which in practice means **iOS Safari**, where `BarcodeDetector` is still not available. Chosen over `qr-scanner`/ZXing specifically because it is **pure JavaScript**: a WebAssembly decoder would force `wasm-unsafe-eval` into `script-src`, and a worker-based one would force `worker-src`. Neither is worth it. The bundle is served from our own origin by Vite — no CDN, no runtime third party.

The only security-header change is `camera=()` → `camera=(self)` in `Permissions-Policy`. `SecurityHeadersTest` asserts the exact value, so the guardian is updated in the same commit that changes it — never after.

### The camera is an input method, not a new validation path

A decoded token is posted to the **same endpoint the manual form already posts to**, hitting `TicketCheckin` and its `UNIQUE(checkins.ticket_id)` guarantee. Nothing about atomicity, revocation or capacity is re-implemented in JavaScript. The browser's only job is turning pixels into a string.

That also means the door keeps working when the camera does not: **manual entry is never removed or hidden**, only relegated. Dead battery on the attendee's phone, a cracked screen, a denied permission, a browser without `getUserMedia` — all fall back to the flow that already shipped.

### Progressive enhancement, so a JS failure cannot close the door

The scanner page is server-rendered with the manual form working. The camera UI is added by JavaScript on top of it. If the bundle fails to load, the page is exactly what it is today — degraded, not broken. **This is the property that matters most**: an event is a fixed moment in time, and "the scanner page is blank" at 8pm cannot be fixed by a deploy (which the deploy freeze forbids anyway).

## Acceptance criteria

### Decoding

- **AC-SCAN-1** *(device)* — Pointing the rear camera at a valid ticket QR decodes it and checks the attendee in, with no third-party network request.
- **AC-SCAN-2** — A decoded token is submitted to the existing check-in endpoint; no second validation path exists. *(test: the scanner posts to `events.checkin`, and check-in remains atomic)*
- **AC-SCAN-3** — Result states are distinguishable and carry the reason: valid → green; already checked in / revoked / event inactive / unknown → red with that reason. *(test: server responses; device pass for the visual)*
- **AC-SCAN-4** *(device)* — After a result the scanner re-arms for the next attendee without a page reload.
- **AC-SCAN-5** *(device)* — Holding one code in frame does not submit it repeatedly; the same token is not re-sent back-to-back within a scan session.

### Degradation

- **AC-SCAN-6** — With the camera denied, missing, or unsupported, manual token entry stays fully usable and check-in still works. *(test: the manual form is present and functional independently of any JS)*
- **AC-SCAN-7** — With JavaScript disabled entirely, the scanner page still renders and the manual form still checks attendees in. *(test: server-rendered form, no JS-only markup for the fallback path)*

### Security

- **AC-SCAN-8** — `Permissions-Policy` grants camera to same-origin only (`camera=(self)`), and still denies microphone and geolocation. *(guardian: `SecurityHeadersTest`)*
- **AC-SCAN-9** — The CSP gains no `unsafe-inline` script source, no `wasm-unsafe-eval`, and no external origin; the `public/.htaccess` copy stays byte-identical. *(guardian: `SecurityHeadersTest` sync test)*
- **AC-SCAN-10** — Both check-in endpoints and the public ticket page are rate-limited; exceeding the limit is rejected without touching the database. *(test: deliberate violation)*
- **AC-SCAN-11** — Only the event's own organizer can open the scanner or check anyone in. *(test: another organizer gets 403)*

## Definition of done

Automated ACs green with name-traced tests · Pint + Larastan + Pest + i18n `--check` clean · CSP/`.htaccess` sync guardian green · device pass (6.5) executed on one iOS and one Android phone with evidence, or explicitly recorded as owner-gated and outstanding · `AGENTS.md` updated.

## Reconciliation

- **2026-07-26 (spike, task 6.2)** — `jsQR@1.4.0` confirmed: pure JS, no wasm, no worker, bundles from our own origin, and **decodes this app's own generated tickets** — a QR produced by `QrPng` reads back as the exact token. That round trip is now a permanent guardian (`QrRoundTripTest`, AC-SCAN-1) rather than one-off spike evidence, because "valid image, unreadable code" is a failure that would otherwise appear for the first time at a venue door. PNG decoding in that script uses **pngjs, not sharp**: sharp ships a platform-specific native binary and the suite runs inside the Linux container against a `node_modules` installed on the macOS host, which fails to load. Pure JS keeps host, container and CI interchangeable.

## Known limitations

- **Online-only** (ADR-002): the door needs connectivity. A venue with no signal falls back to nothing — offline check-in with sync is backlog, and the organizer should know before choosing the venue.
- **iOS requires Safari** for camera access in most embedded/in-app browsers; a link opened inside Instagram or Gmail may not get `getUserMedia`. The page tells the organizer to open it in Safari rather than failing silently.
- **One scanner per event** in v1: the organizer's own account (ADR-004 §7). Two people scanning means sharing a login, which is exactly why staff roles are v2.
