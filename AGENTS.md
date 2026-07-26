# Nexo Events

> Entry point for any AI/agent working on this project. It follows Alvaro's standards system (repo `alvaro`, alvarocdev.com). Keep this file updated: persist here the important context that comes up during work sessions.
> This repo will be public: no secrets, credentials, or sensitive infrastructure details here.

## What this project is

Free event registration and QR ticketing of the Nexo ecosystem: anyone creates an event, people register with just an email, receive a QR ticket, and the organizer validates entry at the door by scanning with their phone. Open source, multi-instance, self-hostable, cookieless — like its siblings (Nexo Links, Nexo Agenda, Nexo Short, Nexo ID). **Current state: Phase 0 (planning) — no product code yet.** Start at [docs/PLAN.md](docs/PLAN.md).

## Stack

Decided in ADR-002 (pending Gate 0 acceptance): Laravel (latest) + MySQL on Hostinger shared hosting, alongside the sibling tools. Paid tickets (v2) will target AR/LatAm (Mercado Pago candidate). QR scanning is a web page (camera + JS decode), no native app.

## How to run it

Docker only, no local PHP. Stateful services are **not** in this repo's `compose.yaml`: MySQL, Mailpit and phpMyAdmin run once for the whole ecosystem in the shared dev environment (`~/dev-environment`, compose project `nexo`). This app is app-runtime-only and reaches them over `host.docker.internal`.

```bash
cd ~/dev-environment && docker compose up -d mysql mailpit   # once per session, shared
cd ~/nexoevents && docker compose up -d                      # this app
docker compose exec laravel.test php artisan migrate
```

| What | Where |
|---|---|
| App | http://localhost:8103 (`APP_PORT`) |
| Vite dev server | port 5178 (`VITE_PORT`) |
| MySQL | `host.docker.internal:3307`, db `nexo_events`, user/pass `dev`/`dev` |
| Mailpit | SMTP `host.docker.internal:1025` · UI http://localhost:8025 |
| phpMyAdmin | http://localhost:8306 |

Ports are fixed per tool (map in `alvaro/templates/dev-environment/README.md`) — 8103/5178 is this tool's slot; 3306 and 8081 belong to the unrelated `work` stack, never use them. `.env` must also pin `WWWUSER`/`WWWGROUP` (501/20 on Alvaro's macOS) so files written in the container stay writable outside.

Quality gate (all must pass before a commit): `./vendor/bin/pint --test`, `./vendor/bin/phpstan analyse`, `./vendor/bin/pest`. Tests never touch the shared MySQL — `phpunit.xml` pins SQLite `:memory:`.

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

- **2026-07-26** — **Silent SSO adopted** from the hardened `nexo-sso-client` template (nonce,
  RP-initiated central logout, and `prompt=none` auto-login all arrived together — this repo was
  still on the pre-nonce copy). Standalone is untouched: with `NEXO_SSO_ENABLED=false` the routes
  never register and the middleware is a pass-through. **The exclusion list is the decision worth
  remembering:** silent SSO exists for *organizers*, who have accounts; **attendees never do**
  (ADR-003), so `e/*` (public event page) and `t/*` (the ticket) are excluded by both path and
  route name. A bounce on `t/{token}` is a person stuck at the venue door with a spinning
  browser — that is the failure this config exists to prevent. `NexoSsoSilentExclusionsTest`
  guards it against this app's real routes, and its last test deliberately proves the middleware
  IS active on a non-excluded page, so a green suite can't mean "inert middleware".
  **Not done on purpose:** the dashboard logout button still posts to the local `logout` route,
  not `nexo-sso.logout` (same as the sibling tools). Rewiring it belongs to the SSO activation
  batch (PLAN task 8.6) together with registering the prod client and `post_logout_redirect_uri`.
- **2026-07-26** — **Local dev migrated to the shared `nexo` Docker stack** (last of the 6 tools to
  migrate). Per-project MySQL/Mailpit are gone: `compose.yaml` is now app-runtime-only and the app
  reaches the shared services via `host.docker.internal`. The `nexo_events` database was dumped from
  the old `nexoevents_sail-mysql` volume and imported into `nexo-mysql-1` (row counts verified table
  by table; the DB held only the 7 migration rows — no dev data existed). Backup kept at
  `~/dev-environment/backups/2026-07-26-migracion-nexo/nexo_events.sql`; old containers and the
  volume removed only after `migrate:status`, HTTP 200 and the full suite verified green.
  **Gotcha for any future migration of this kind:** the old per-project MySQL could not simply be
  started to dump it — its compose published `FORWARD_DB_PORT=3307`, the port the shared stack now
  owns, so `docker start` failed with "port is already allocated". The fix is to mount the old
  volume in a throwaway `mysql:8.4` container that publishes **no** ports and dump from there.
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
