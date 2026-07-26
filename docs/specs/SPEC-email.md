# SPEC — Ticket email & organizer verification (PLAN Phase 5)

> Written before the code (`planning-by-stages`). Every AC below has ≥1 test whose name quotes its id, so `grep "AC-EMAIL-3" tests/` finds the proof. Governing decisions: **ADR-005** (transactional email), **ADR-003** (accounts), **ADR-007 §1** (verified email to publish).

## Purpose

Close the first deferred MVP promise: the attendee's ticket arrives **by email**, not only on screen. Today `PublicEventController::register()` redirects to `t/{token}` and that is the only time the raw token is ever shown — lose the tab and the ticket is unrecoverable. This phase makes the email the durable copy, adds a way to ask for it again, and stops unverified organizers from publishing.

## Scope

**In:** one queued "Your ticket" email (event summary + QR + ticket link); a resend flow; organizer email verification with a publish gate; queue draining via the scheduler.

**Out:** reminders, organizer digests, any second template (ADR-005 §6 — v1's template surface is exactly one email). Attendee accounts (ADR-003). Provider SDKs — SMTP over env config only, so self-hosters plug in whatever they have (ADR-005 §1).

## Design decisions

### The QR travels as an inline PNG, rendered with GD

ADR-005 §7 requires the QR as an embedded image with the link as fallback, and leaves the technique to this spike. Resolved:

- **Not a data-URI** — Gmail strips them, which is precisely the case the ADR flagged.
- **Not SVG** — the on-screen `QrSvg` renderer is inline SVG; email clients (Outlook above all) do not render it.
- **Inline PNG attached with a Content-ID**, referenced from the template. Universally supported.
- **Rendered with GD, not Imagick.** `bacon/bacon-qr-code` only ships PNG through `ImagickImageBackEnd`, but production is Hostinger shared hosting and **Imagick is a PECL extension we cannot assume is installed** — the local Sail image having it proves nothing about the host. GD is bundled with essentially every PHP build, so a new `QrPng` service draws the encoder's matrix with GD directly (~30 lines, no new dependency) and `composer.json` declares `ext-gd`. A missing image renderer must never be the reason a ticket email fails to send.

The ticket **link is the real fallback**: every client shows it, and it reaches the same on-screen QR the door already accepts.

### Registration never waits for mail

Sending is queued (`ShouldQueue`, database queue, ADR-005 §5). The registration request issues the ticket, redirects to the ticket screen, and returns — a slow or dead SMTP degrades to "the email is late", never to "registration failed". The on-screen QR is valid regardless.

### Resend does not disclose who is registered

The resend form answers with the same neutral message whether or not that address holds a ticket. Otherwise the public event page becomes an attendee-list oracle for anyone with a browser. Mail only ever goes to the address that actually registered, so this is not a delivery vector — the risk it does carry (nuisance sends) is what the rate limit is for.

### Verification gates publishing, nothing else

An organizer can sign up, create and edit drafts unverified. Only `publish` requires a verified email (ADR-007 §1) — that is the action that puts content under the instance's domain. SSO organizers arrive with the provider's `email_verified` claim and must not be asked to verify twice.

## Acceptance criteria

### Ticket delivery

- **AC-EMAIL-1** — A successful registration for a published event queues exactly one ticket email to the attendee's address.
- **AC-EMAIL-2** — The email carries the event title, start date/time, venue, attendee name, the QR as an inline image, and a link to the ticket page.
- **AC-EMAIL-3** — The QR image in the email encodes the *same* opaque token as the on-screen QR and as the ticket link. The raw token is never written to the application log.
- **AC-EMAIL-4** — A duplicate registration (same email, same event) issues no new ticket and queues no new email; the response points the attendee at the resend flow.
- **AC-EMAIL-5** — Mail is queued, not sent in-request: registration succeeds and the ticket screen renders even when the mail transport throws.
- **AC-EMAIL-6** — The email is rendered in the locale the attendee registered in (es/en/pt).

### Resend

- **AC-RESEND-1** — Given a registered address, the resend request re-queues the ticket email for that ticket, with the same token (no new ticket, no new token).
- **AC-RESEND-2** — The response is identical for a registered and an unregistered address; no ticket is disclosed either way.
- **AC-RESEND-3** — Resend is rate-limited; over the limit the request is rejected with 429 and no mail is queued.

### Organizer verification

- **AC-VERIFY-1** — Registering as an organizer queues a verification email; the account starts unverified.
- **AC-VERIFY-2** — An unverified organizer cannot publish: the attempt is refused and the event remains in its previous status.
- **AC-VERIFY-3** — After verifying, the same organizer can publish that event.
- **AC-VERIFY-4** — An organizer signed in through Nexo ID with `email_verified` true is treated as verified and is never asked to verify again.
- **AC-VERIFY-5** — Verification gates publishing only: creating, editing, closing and cancelling stay available while unverified.

### Queue & scheduler

- **AC-QUEUE-1** — The scheduler drains the database queue, and the schedule contains no reference to a command that does not exist (`schedule:list` resolves every entry).

## Definition of done

All ACs green with name-traced tests · Pint + Larastan + Pest + i18n `--check` clean · local end-to-end verified in Mailpit (register → email with a scannable QR → link opens the ticket) · deliverability checklist for the hosted instance handed to the owner (real-inbox evidence is owner-gated, needs provider credentials) · `AGENTS.md` updated.

## Known limitations (carried, not solved here)

- **Free-tier daily cap** (~300/day on the planned provider, ADR-005): a viral event exhausts it and the queue delivers late. The on-screen QR and the ticket link keep working throughout. Monitoring volume is an operational task (Phase 8), and the paid tier is the pressure valve.
- **No delivery receipts**: the app knows a message was handed to SMTP, not that it landed. Bounce handling is post-v1.
