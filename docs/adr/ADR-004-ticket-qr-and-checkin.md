# ADR-004 — Ticket QR & check-in: opaque server-side token, DB-enforced atomicity

- **Date:** 2026-07-19
- **Status:** Accepted (2026-07-26)

## Context

The brief's hard technical requirements (§4, non-negotiable at the *what* level): the QR must not encode attendee data; validity lives server-side and is revocable; double-scan and last-spot races are THE classic bugs of ticketing systems and must be solved atomically **with tests**. The brief's §8 data model is input to re-evaluate, not a decision.

## Decision

### QR token

1. The QR encodes only an **opaque random token** (CSPRNG, ≥128 bits of entropy, URL-safe encoding — exact format in the phase SPEC). It is a pointer to the ticket on the server; it carries **no attendee data** and is **not** derived from ticket fields (no HMAC-of-data — revocation and simplicity beat signature cleverness when the check always hits the DB anyway).
2. The DB stores a **hash of the token** (SHA-256), not the raw token — a leaked DB must not allow forging valid QR entries. The raw token exists only in the QR/ticket link delivered to the attendee. Same pattern as password-reset tokens.
3. The same token backs the attendee's "view my ticket" URL. Revocation = ticket status change; the token never needs rotating in v1.

### Atomicity (mandatory race-condition ACs)

4. **Check-in**: single conditional statement + unique constraint, inside one transaction —
   `UPDATE tickets SET status='checked_in' WHERE id=? AND status='valid'` (affected-rows check) plus insert into `checkins` with `ticket_id UNIQUE`. Two simultaneous scans of the same ticket resolve to exactly one green. The SPEC maps this to numbered ACs with race tests (two concurrent transactions in test).
5. **Capacity**: registration locks the event row (`SELECT … FOR UPDATE`), checks issued-ticket count vs capacity, then inserts — all in one transaction. Two simultaneous registrations for the last spot yield exactly one ticket. Also `UNIQUE(event_id, attendee_email)`: one ticket per email per event (dupe-guard + abuse dampener).

### Check-in mechanics & permissions

6. Scanning is a **web page on the organizer's phone** (camera + JS decode, ADR-002 §5). Result UX: valid → green; already checked in / revoked / wrong event / unknown → red **with the reason**. A **manual fallback** (search the registered list, check in through the same atomic path) covers broken QRs and dead cameras.
7. **Only the event's organizer can scan in MVP.** Staff/collaborator scanner roles are v2 (needs an invitation/permission model). *Confirm at Gate 0.*
8. **Online-only in v1** (known limitation, brief §4): no connectivity at the door means no QR validation. The registered list is server-rendered too — v1 offers no offline mode; offline-with-sync is backlog.

### Minimal data model (MVP, re-evaluated from brief §8)

```
users(id, name, email UNIQUE, email_verified_at, password, timestamps)            -- organizers only in v1
events(id, organizer_id FK, slug UNIQUE, title, description, starts_at, ends_at?,
       venue, capacity NULL, image_path NULL, status, registration_closed_at NULL, timestamps)
       -- status: draft | published | cancelled | killed  (kill-switch = status, ADR-007)
tickets(id UUID, event_id FK, attendee_name, attendee_email, qr_token_hash UNIQUE,
        status, timestamps)  -- status: valid | checked_in | revoked
        -- UNIQUE(event_id, attendee_email)
checkins(id, ticket_id FK UNIQUE, checked_in_by FK users, checked_in_at)
event_reports(id, event_id FK, reason, reporter_email NULL, created_at)           -- ADR-007
```

Deltas vs the brief: `slug` (shareable URLs), richer `status` lifecycle (kill-switch), token stored hashed, per-event email uniqueness, `event_reports`. Exact columns are SPEC territory; this fixes the shape.

## Alternatives considered

- **HMAC-signed QR payload (data in the QR)** — rejected: enables offline verification we don't ship in v1, while adding key management and losing free revocation; and any attendee data in the QR violates the brief.
- **Raw token stored in DB** — rejected: one `SELECT` away from mass ticket forgery; hashing costs nothing.
- **App-level capacity check without row lock** — rejected: it *is* the last-spot race bug.
- **Staff scanning in MVP** — deferred: permission model isn't needed to validate the core loop.

## Consequences

- Check-in correctness is enforced by the database (unique constraints + transactions), not by application discipline — the race tests prove it and stay as regression guards.
- Losing the ticket email means re-requesting it (re-send flow in SPEC); there is no attendee account to recover from in v1 (ADR-003).
- The check-in endpoint is a hot authenticated path — rate limiting still applies to absorb scan-spam (ADR-007).
