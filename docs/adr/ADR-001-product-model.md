# ADR-001 — Product model: free events v1, public multi-organizer, open source multi-instance

- **Date:** 2026-07-19
- **Status:** Accepted (2026-07-26; records product decisions already closed by Alvaro where noted)

## Context

The evaluation brief (`nexoevents.md`, 2026-07-20) closed the core product decisions; this ADR records them as the immutable baseline and resolves the points the brief left to the planning (license/distribution model, naming).

## Decision

1. **v1 is free events with registration only** — no payment gateway, no fees, no refunds. Paid ticketing is v2, aimed at the **Argentina/LatAm market** (Mercado Pago as natural candidate). *(Closed by Alvaro, 2026-07-20.)*
2. **Public multi-organizer from day 1**: anyone with an account can create events, like the sibling Nexo tools. This forces the anti-abuse baseline of ADR-007. *(Closed by Alvaro, 2026-07-20.)*
3. **QR check-in at the door is part of the MVP** — without entry validation the tickets are decorative. *(Closed by Alvaro, 2026-07-20.)*
4. **Open source, multi-instance, self-hostable** — like the rest of the ecosystem: MIT license, `NEXO_ATTRIBUTION_*` env-based footer, repo docs in English. Repo starts **private**; it goes public only after passing `audit-open-source`. *(Confirmed by Alvaro, 2026-07-19.)*
5. Naming (proposed, resolve at Gate 0): repo slug **`nexo-events`** (sibling pattern, per nexoid Gate 0 precedent), GitHub org **`nexo-tools`**, hosted instance **`nexoevents.alvarocdev.com`** (consistent with nexolinks./nexoagenda./nexoid.).

## Alternatives considered

- **Paid tickets in v1** — rejected in the brief: gateway + refunds + legal is a project in itself and delays the core loop.
- **Curated/invite-only organizers** — rejected: against the public-tool DNA of the ecosystem; abuse is handled by ADR-007 instead.
- **Closed source** — rejected: multi-instance open source is the ecosystem norm and was confirmed.

## Consequences

- Anti-abuse (accounts, rate limiting, report, kill-switch) is MVP scope, not hardening-later (ADR-007).
- Everything public-facing ships with i18n (es/en/pt), SEO base, and the attribution footer from the first commit (ecosystem standards).
- The data model must not paint payments into a corner, but v1 implements none of it.
