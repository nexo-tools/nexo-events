# Nexo Events

> Entry point for any AI/agent working on this project. It follows Alvaro's standards system (repo `alvaro`, alvarocdev.com). Keep this file updated: persist here the important context that comes up during work sessions.
> This repo will be public: no secrets, credentials, or sensitive infrastructure details here.

## What this project is

Free event registration and QR ticketing of the Nexo ecosystem: anyone creates an event, people register with just an email, receive a QR ticket, and the organizer validates entry at the door by scanning with their phone. Open source, multi-instance, self-hostable, cookieless — like its siblings (Nexo Links, Nexo Agenda, Nexo Short, Nexo ID). **Current state: Phase 0 (planning) — no product code yet.** Start at [docs/PLAN.md](docs/PLAN.md).

## Stack

Decided in ADR-002 (pending Gate 0 acceptance): Laravel (latest) + MySQL on Hostinger shared hosting, alongside the sibling tools. Paid tickets (v2) will target AR/LatAm (Mercado Pago candidate). QR scanning is a web page (camera + JS decode), no native app.

## How to run it

Nothing to run yet — Phase 1 scaffolds the app (Sail-based, no local PHP, per `laravel-bootstrap-docker-only`).

## Production

Not deployed. Planned: `nexoevents.alvarocdev.com` (proposed, Gate 0 decides) via the `deploy-laravel-hostinger` skill, in Phase 4. Operational rule already decided: no deploys/maintenance while any event is in its door window — downtime during a live event strands people at the door.

## Project conventions

- This project runs on the `planning-by-stages` skill: [docs/PLAN.md](docs/PLAN.md) is the governing doc — one numbered task at a time, gate per phase with owner sign-off, ADRs in [docs/adr/](docs/adr/), SPEC before code with AC↔test traceability.
- Docs in English (repo will be public). Communication with Alvaro in Spanish.
- Nexo product conventions apply (siblings nexo-links/nexo-agenda as reference): zero external requests at runtime, i18n es/en/pt, `NEXO_ATTRIBUTION_*` footer, strict CSP + sync test, Pest/Pint/Larastan + CI, cookieless analytics (VisitorHash).
- Non-negotiables from the brief, encoded in ADR-004: opaque server-side QR tokens (stored hashed), atomic check-in and capacity **with race-condition tests**.

## Key decisions

- **2026-07-19** — Phase 0 executed: SCOPE, ADRs 001–007 (Proposed), PLAN, formalization. **Gate 0 sign-off pending.** Decisions taken by Alvaro during planning: stack = Laravel + Hostinger; attendees = email-only in v1 (no attendee accounts); open source multi-instance confirmed.
- **2026-07-19** — Coordination with Nexo ID settled by their accepted ADR-004: Nexo Events ships standalone organizer auth; Nexo ID is an optional env-configured SSO provider, integrated post-MVP via their Phase 3 client pattern. Cross-note left in nexoid's AGENTS.md. No launch coupling in either direction.
- **2026-07-19** — `nexoevents.md` (root) is the pre-planning evaluation brief: treat as **input, not decisions**, except its §2 (product decisions closed by Alvaro). ADR-004 supersedes its §8 data model. It is Spanish and pre-decision — candidate to drop/move before the repo goes public (same call as nexoid's `nexo-id.md`). `planning-prompt.md` is gitignored (local paths).

## Accumulated context

- **2026-07-23** — **MVP core built and green** (ecosystem run, Gate 0 authorized by Alvaro to
  proceed). Scaffolded by cloning the sibling **nexo-agenda** infrastructure (Laravel 13 +
  Sail on the shared dev-environment, Pest/Pint/Larastan L6, CI, strict CSP + `.htaccess`
  sync test, i18n es/en/pt generator + guardian, `firebase/php-jwt`, the nexo-sso-client
  template) and replacing the bookings domain with events. **Built + tested (61 tests, 1
  node-skip; Pint + Larastan + composer audit + i18n `--check` all green):** organizer
  standalone auth (register/login/reset — attendees never get an account, ADR-003); Event
  model + `EventStatus` (draft/published/closed/cancelled/killed) + slug + organizer CRUD +
  publish/close/cancel + ownership guards; public event page (`e/{slug}`); **email-only
  attendee registration** with `EventRegistrar` — **atomic capacity** (row lock; last spot →
  exactly one ticket; `UNIQUE(event_id,email)` idempotent) + honeypot + rate limit; **QR
  ticket = opaque token stored only as `sha256` hash** (`token_hash`), shown on screen with a
  `QrSvg` QR; **atomic door check-in** via `TicketCheckin` — the `UNIQUE(checkins.ticket_id)`
  constraint makes a double scan resolve to one entry (proven by test) + manual fallback from
  the registered list + organizer-only guard. SSO client cloned + tested (off by default;
  redirect adapted to `route('dashboard')`; success tests point at dashboard). Data model:
  `events` / `tickets` / `checkins` migrations. Tests skip Vite (`withoutVite` in Pest.php).
  **Deferred (remaining MVP work, not yet built):** transactional ticket **email** delivery
  (Phase 2 deliverability spike — currently the ticket is shown on-screen only); **camera**
  QR-decode JS at the door (Phase 3 spike — scanner is manual token entry for now); organizer
  **email verification** + publish-gate; event **SEO/JSON-LD/sitemap** + VisitorHash view
  counter; report + kill-switch **UI** (the `killed` status exists); production deploy (Phase
  4, owner-gated). Source & full plan: `~/alvaro/inbox/ecosystem-audit` + `docs/PLAN.md`.
- **2026-07-19** — Phase 0 done up to task 0.7 (gate presentation). Flags for Alvaro at Gate 0: (1) manual check-in fallback added to MVP beyond the brief, (2) staff scanning deferred to v2, (3) naming (`nexo-events` / `nexoevents.alvarocdev.com`). Known limitations recorded: online-only check-in (v1), email free-tier daily cap (~300/day). Repo initialized and private remote created in `nexo-tools`; **nothing committed yet** (owner review pending per standards). Next after sign-off: Phase 1, opening with its SPEC (task 1.1).
