# ADR-008 — Resending a ticket rotates its token

- **Date:** 2026-07-26
- **Status:** Accepted
- **Supersedes:** ADR-004 §3 (last sentence: "the token never needs rotating in v1")

## Context

Two accepted decisions collide as soon as the resend flow is built:

- **ADR-004 §2** — the database stores only `sha256(token)`. The raw token exists exactly once, in the QR and link delivered to the attendee.
- **ADR-005 §6** — losing the ticket email is answered by a re-send flow.

A resend cannot reproduce a token that is only stored as a hash. ADR-004 §3 assumed rotation would never be needed; building the flow proves otherwise. Something has to give, and it must not be the hashing.

## Decision

1. **A resend mints a new opaque token** (same CSPRNG, same shape as ADR-004 §1), stores its hash on the existing ticket row, and emails the ticket with the new QR. The ticket keeps its identity — same row, same attendee, same event, same check-in history. Only the credential changes.
2. **The previous QR and ticket link stop working the moment a resend succeeds.** This is stated in the ticket email so nobody is surprised at a door.
3. **The resend only ever delivers to the address already registered** for that event, and the HTTP response is identical whether or not that address holds a ticket (no attendee-list oracle). It is rate-limited like every public write (ADR-007 §2).
4. **Revoked tickets are never resent.** A revoked ticket stays dead; rotation must not become a way to revive one.
5. Rotation happens **only** on an explicit resend request. Normal registration, viewing and check-in never rotate — the check-in path is untouched and still validates by hashing what it scans.

## Alternatives considered

- **Store the raw token** — rejected outright: ADR-004 §2 exists precisely so a leaked database cannot forge entry.
- **Store the token reversibly encrypted** — the same exposure as plaintext for anyone who reaches both the data and the key, in exchange for key management. No.
- **Recover by ticket id instead of token** (signed link → render the existing QR) — impossible without the raw token, and it would reintroduce the guessable identifier the opaque token was chosen to avoid.
- **No resend at all** — contradicts ADR-005 §6, and "I lost the email / closed the tab" is the single most likely support request for a free event.

## Consequences

- **A resend is a credential rotation.** An attendee who forwarded their ticket to someone and then requests a resend kills the forwarded copy — usually the behaviour you want from a ticket.
- **Someone can trigger a rotation for an address they do not control**, breaking a QR the legitimate holder already had. It is self-healing (the replacement lands in that same inbox, never the requester's) and rate-limited. The alternative — confirming whether an address is registered before acting — leaks the attendee list, which is worse.
- Organizers see nothing change: the registered list, capacity and check-in state are all keyed to the ticket row, not the token.
