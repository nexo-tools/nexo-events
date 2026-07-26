# ADR-007 — Anti-abuse & analytics: rate limits, report + kill-switch, cookieless metrics

- **Date:** 2026-07-19
- **Status:** Accepted (2026-07-26)

## Context

Public multi-organizer means anyone can publish content under the instance's domain — spam events, phishing lures, harvesting registrations. The brief closes anti-abuse as day-1 scope (§4): mandatory accounts, rate limiting, report mechanism, per-event kill-switch — same pattern as Nexo Short. Ecosystem principles add: anti-bot must be self-hosted and cookieless (never reCAPTCHA/third parties), analytics must be cookieless with no raw IPs.

## Decision

### Anti-abuse (MVP)

1. **Accounts + verified email to publish**: creating events requires an organizer account (ADR-003); an event cannot reach `published` until the organizer's email is verified. Cheap, effective first gate.
2. **Rate limiting on every public write**: registration (per IP + per event), organizer signup/login/password reset, report submission, ticket re-send, and the check-in endpoint (scan-spam). Limits enforced by Laravel rate limiters; concrete numbers live in the SPEC with **deliberate-violation tests** (a blocked request is an AC, per standards).
3. **Report mechanism**: public "report this event" on every event page → `event_reports` row + notification to the instance admin. No login required to report (optional reporter email for follow-up).
4. **Kill-switch per event**: admin action setting `status = killed` — public page returns an unavailable notice, registration closes, all tickets stop validating at check-in (red, with reason), short link (if any) effectively dies. Reversible (audit trail in SPEC).
5. **Anti-bot challenge: none at launch.** If registration-bot pressure appears, add a self-hosted, cookieless proof-of-work challenge (ALTCHA-style) — **never** reCAPTCHA or any cookie-bearing third party (ecosystem principle). The registration form ships honeypot + rate limits only, to protect conversion.

### Analytics (MVP)

6. **Cookieless product counters** via the **VisitorHash** pattern (canonical in nexo-links, CATALOG.md): daily-rotating SHA-256 of app key + date + IP + UA, nothing persisted that identifies a visitor. Metrics: event-page views (deduped per visitor-day) and registrations — the two numbers an organizer actually wants.
7. **No third-party analytics, no cookies, zero external requests at runtime** — the Nexo baseline. Operational logging (auth failures, rate-limit hits, kill-switch usage) via standard app logs.

## Alternatives considered

- **reCAPTCHA / hosted CAPTCHA** — rejected on principle: third-party + cookies contradicts the privacy pitch of the whole product family.
- **Pre-moderation of events** — rejected: kills the instant-publish value of a public tool; report + kill-switch handles the tail.
- **Google Analytics or similar** — rejected on the same privacy principle; VisitorHash covers the need.

## Consequences

- Admin needs a minimal surface in MVP: view reports, kill/restore an event. Kept deliberately tiny (SPEC decides: CLI command vs minimal admin page).
- Rate limits and the kill-switch get name-traced ACs and deliberate-violation tests — enforcement is mechanical, not aspirational.
- If bot pressure materializes, the ALTCHA-style challenge is a scoped, pre-decided addition — no re-litigation under fire.
