# ADR-003 — Accounts & auth: mandatory local organizer accounts; email-only attendees; Nexo ID as optional SSO later

- **Date:** 2026-07-19
- **Status:** Proposed (attendee model chosen by Alvaro, 2026-07-19)

## Context

Two distinct populations (brief §5):

- **Organizers** create public content → accounts are mandatory, no discussion (anti-abuse + management).
- **Attendees** register for a free event → every ounce of friction kills conversion; nobody wants to create an account for one free ticket.

Nexo ID exists and has passed its own Gate 0 (`/Users/alvarocarrizales/nexoid`). Its **ADR-004** (accepted) settles how tools relate to it: *every tool keeps standalone local auth; Nexo ID is an optional, env-configured OIDC SSO provider* (OAuth 2.0 + PKCE, nexoid ADR-003). Its PLAN builds the reusable Laravel client pattern in its Phase 3 and explicitly notes "Nexo Events integrates whenever it's born (needs only the Phase 3 pattern)".

## Decision

1. **Organizers: mandatory account with standalone local Laravel auth** (registration, login, email verification, password reset — all rate-limited). Publishing an event requires a verified email (ADR-007). This is exactly the standalone mode nexoid ADR-004 requires every tool to ship, so self-hosting Nexo Events alone works with zero extra services.
2. **Attendees: email only in v1** — name + email produce a ticket; no password, no account. Chosen by Alvaro (2026-07-19) following the brief's suggested path. The ticket link (opaque token, ADR-004) is the attendee's access to their ticket.
3. **Nexo ID integration is post-MVP and optional**: when nexoid's Phase 3 client pattern exists, Nexo Events adds "Sign in with Nexo ID" for organizers via `NEXO_SSO_*` env config (nexoid ADR-004 contract). The launch of Nexo Events does **not** couple to nexoid's timeline in any direction.
4. **Attendee accounts ("save your tickets") are v2**, arriving through the same Nexo ID integration — optional, never required to register for an event.
5. Cross-registration: this decision is noted in nexoid's `AGENTS.md` (accumulated context) so both plans stay coordinated.

## Alternatives considered

- **Mandatory attendee accounts** — rejected: kills registration conversion for free events (brief §5 flags it as the risk).
- **Own optional attendee accounts in v1 (without nexoid)** — rejected by Alvaro: extra auth surface in the MVP for a feature the ecosystem will provide better via Nexo ID.
- **Launching organizer auth on Nexo ID (SSO-only)** — rejected: nexoid is in Phase 0→1; coupling would block this project and contradict nexoid ADR-004's standalone-first model.

## Consequences

- MVP auth surface is small and boring: one `users` table for organizers, standard Laravel auth, no OAuth code yet.
- Attendee PII in v1 is minimal (name + email per ticket) — good for privacy posture and for GDPR-ish hygiene.
- When SSO lands, it is additive (account linking per nexoid ADR-005 pattern); no migration machinery.
- Attendees can't self-serve "all my tickets" across events in v1 (each ticket travels by email/link) — accepted trade-off, solved by v2 accounts.
