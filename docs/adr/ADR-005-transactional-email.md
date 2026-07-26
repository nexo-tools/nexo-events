# ADR-005 — Transactional email: env-configured SMTP; Brevo free tier for the hosted instance

- **Date:** 2026-07-19
- **Status:** Accepted (2026-07-26)

## Context

The ticket arrives by email, so deliverability is a hard requirement (brief §4): mail sent from the shared host's SMTP routinely lands in spam. The brief mandates evaluating a transactional provider with a free tier (Resend, Brevo, …). Cost target for v1 is $0. As a multi-instance product, self-hosters must be able to plug in whatever mail system they have.

## Decision

1. **The app talks standard Laravel mail over SMTP, configured by env** — no provider SDK dependency. Any self-hosted instance works with any SMTP (including "bad" ones — their choice).
2. **Alvaro's hosted instance uses Brevo's SMTP relay (free tier, 300 emails/day)** as first candidate. Rationale vs Resend: 3× the daily free headroom (300/day vs 100/day) — ticket delivery spikes with registrations on announcement day, so the daily cap is the binding constraint — and plain SMTP transport keeps decision §1 honest.
3. **Sender identity**: `nexoevents@alvarocdev.com` with the domain authenticated in the provider (SPF + DKIM records for alvarocdev.com / provider subdomain as required). Never the Hostinger SMTP for ticket mail.
4. **Deliverability is validated by a spike task** at the start of the tickets phase (PLAN Phase 2): send real tickets to Gmail/Outlook/Yahoo inboxes and verify inbox placement, SPF/DKIM/DMARC alignment, and QR rendering — before building on top. Findings reconcile this ADR.
5. **Sending is queued** (database queue + cron `queue:work --stop-when-empty`, per Hostinger's no-daemon constraint, ADR-002) so a slow SMTP never blocks the registration request; the ticket screen shows the QR immediately regardless of email latency.
6. **v1 template surface is exactly one email**: "Your ticket" (event summary + QR + ticket link). Registration confirmation *is* the ticket email. No reminders, no organizer digests (backlog).
7. **QR in the email**: rendered server-side as an embedded/attached image (data-URI images are blocked by Gmail; exact embedding technique settled in the spike) with the ticket link as fallback — the link always works even if images don't load.

## Alternatives considered

- **Hostinger shared SMTP** — rejected: the spam problem this ADR exists to avoid (brief §4).
- **Resend** — solid API and DX, but 100/day free cap binds first and its value-add (API/SDK) is what §1 avoids. Second candidate if Brevo disappoints in the spike.
- **Amazon SES** — cheapest at scale, but no meaningful free tier without AWS residency and heavier setup; overkill for v1 volume.
- **Sending synchronously in-request** — rejected: couples registration UX to a third party's latency.

## Consequences

- Free-tier daily cap (300/day) is a **known limitation**: one viral event can exhaust it and delay ticket emails (queue keeps them flowing next day; the on-screen QR still works). Monitor volume; a paid tier is the pressure valve, breaking the $0 target knowingly.
- Provider is swappable by env alone — also true for the hosted instance if Brevo's free tier degrades.
- DNS records (SPF/DKIM) touch alvarocdev.com — coordinate with whatever mail already sends from that domain before adding them (spike checklist).
