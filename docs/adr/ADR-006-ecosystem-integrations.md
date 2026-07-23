# ADR-006 — Ecosystem integrations: post-MVP, via public APIs, never blocking

- **Date:** 2026-07-19
- **Status:** Proposed

## Context

The brief (§6) lists natural integrations: Nexo Short (auto short link per event — would be the ecosystem's first real internal integration), Nexo Links (featured event in bio), Nexo Agenda (event ↔ agenda bridge), Nexo ID (organizer SSO), NexoTools hub listing. Reality check (2026-07-19): **Nexo Short is itself in Phase 0** (planning, awaiting its Gate 0) and Nexo ID is entering its Phase 1 — there is nothing live to integrate against. The planning directive is explicit: coordinate, don't block.

## Decision

1. **No sibling integration is MVP scope.** Nexo Events launches fully standalone; every integration is additive later.
2. **Integration posture**: tools talk over **public HTTP APIs** with explicit contracts — never shared databases, never reaching into another tool's schema. Env-configured, like everything multi-instance (a self-hosted Nexo Events simply leaves `NEXO_*` integration vars unset).
3. **Nexo Short (short link per event)**: post-MVP, first in line once Nexo Short is live and exposes a link-creation API. Hook designed in from day 1 at zero cost: every event has a canonical public URL (slug), and the event model carries a nullable `short_url` — when integration lands, creation/publishing populates it. Coordination note goes to Nexo Short's planning so its API contemplates programmatic creation by a trusted client.
4. **Nexo ID (organizer SSO)**: governed by ADR-003 — post-MVP, via nexoid's Phase 3 client pattern.
5. **Nexo Links / Nexo Agenda**: v2 exploration, no design commitments now.
6. **NexoTools hub**: "coming soon" entry for Nexo Events — a content change in the `nexotools` project, not code here; flagged as a coordination note for Alvaro.

## Alternatives considered

- **Shipping the Nexo Short link in MVP** — rejected: Nexo Short doesn't exist yet; blocking a launch on a sibling's roadmap is exactly what the directive forbids.
- **Direct DB/internal coupling between hosted siblings** — rejected: breaks self-hostability and the multi-instance story; nexoid's ADRs establish the same API-only posture.

## Consequences

- The MVP carries two tiny future-proofing artifacts (`slug` canonical URLs, nullable `short_url`) and nothing else.
- Two coordination notes leave this repo: one to Nexo Short (programmatic link-creation API) and one to nexotools ("coming soon" entry) — both for Alvaro to route, neither blocking.
- Integration order later is free to follow ecosystem reality (whichever sibling ships first).
