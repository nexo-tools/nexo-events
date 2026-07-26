# Nexo Events

> Entry point for any AI/agent working on this project. It follows Alvaro's standards system (repo `alvaro`, alvarocdev.com). Keep this file updated: persist here the important context that comes up during work sessions.
> This repo will be public: no secrets, credentials, or sensitive infrastructure details here.

## What this project is

Free event registration and QR ticketing of the Nexo ecosystem: anyone creates an event, people register with just an email, receive a QR ticket, and the organizer validates entry at the door by scanning with their phone. Open source, multi-instance, self-hostable, cookieless — like its siblings (Nexo Links, Nexo Agenda, Nexo Short, Nexo ID). **Current state: MVP built + branded, pre-launch, not deployed** — working through the launch phases. Start at [docs/PLAN.md](docs/PLAN.md), which carries the executed history, the verified gap register and the phase in progress.

## Stack

Decided in ADR-002 (Accepted 2026-07-26): Laravel 13 + MySQL on Hostinger shared hosting, alongside the sibling tools. Paid tickets (v2) will target AR/LatAm (Mercado Pago candidate). QR scanning is a web page (camera + JS decode), no native app.

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

**LIVE at https://nexoevents.alvarocdev.com since 2026-07-26** (PLAN 8.2/8.3). Deployed via `gh workflow run deploy.yml`; the app lives in `~/domains/alvarocdev.com/nexo-events` (hyphenated slug, like the siblings) with `public_html/nexoevents` symlinked to its `public/`. Smoke passed: every public surface and brand asset 200, the strict CSP survives LiteSpeed, `camera=(self)` present, ticket pages `noindex`, sitemap listing published events, and a real registration delivered its ticket email through SMTP with no failed jobs. Operational rule already decided: no deploys/maintenance while any event is in its door window — downtime during a live event strands people at the door, so the deploy script itself refuses to run then (task 8.1).

## Project conventions

- This project runs on the `planning-by-stages` skill: [docs/PLAN.md](docs/PLAN.md) is the governing doc — one numbered task at a time, gate per phase with owner sign-off, ADRs in [docs/adr/](docs/adr/), SPEC before code with AC↔test traceability.
- Docs in English (repo will be public). Communication with Alvaro in Spanish.
- Nexo product conventions apply (siblings nexo-links/nexo-agenda as reference): zero external requests at runtime, i18n es/en/pt, `NEXO_ATTRIBUTION_*` footer, strict CSP + sync test, Pest/Pint/Larastan + CI, cookieless analytics (VisitorHash).
- Non-negotiables from the brief, encoded in ADR-004: opaque server-side QR tokens (stored hashed), atomic check-in and capacity **with race-condition tests**.

## Key decisions

- **2026-07-26** — **Plan reconciled with reality and ADRs 001–007 Accepted** (sign-off by Alvaro). Owner flags resolved: email provider account and the `nexoevents.alvarocdev.com` subdomain already exist; launch happens when development ends (no calendar date); SSO and beacon activation are launch-independent and validated in task 8.6 once the in-flight nexoid/nexotools work is stable; **event image upload deferred post-v1** (SCOPE amended below). The PLAN's gap register — built from a full code audit, not from the docs — is the authoritative list of what is actually missing.
- **2026-07-19** — Phase 0 executed: SCOPE, ADRs 001–007, PLAN, formalization. Decisions taken by Alvaro during planning: stack = Laravel + Hostinger; attendees = email-only in v1 (no attendee accounts); open source multi-instance confirmed.
- **2026-07-19** — Coordination with Nexo ID settled by their accepted ADR-004: Nexo Events ships standalone organizer auth; Nexo ID is an optional env-configured SSO provider, integrated post-MVP via their Phase 3 client pattern. Cross-note left in nexoid's AGENTS.md. No launch coupling in either direction.
- **2026-07-19** — `nexoevents.md` (root) is the pre-planning evaluation brief: treat as **input, not decisions**, except its §2 (product decisions closed by Alvaro). ADR-004 supersedes its §8 data model. It is Spanish and pre-decision. **Resolved 2026-07-26 (task 9.1):** it stays gitignored and out of the repo — never tracked, so nothing to scrub — and the public docs no longer link to it. `planning-prompt.md` and `CLAUDE.local.md` likewise.

## Accumulated context

- **2026-07-26** — **First production deploy done.** Three things blocked it, all worth
  remembering: (1) the org's deploy secrets are scoped to **public repositories** and this repo
  was private, so they expanded to empty strings and `ssh` failed with `Bad port ''` — and the
  server could not clone a private repo either; making the repo public fixed both at once.
  (2) `DEPLOY_PATH` must use the **hyphenated** slug `nexo-events`, matching the siblings.
  (3) `config/app.php` had `'timezone' => 'UTC'` **hardcoded**, so `APP_TIMEZONE` did nothing —
  fixed, because in an events app that silently shifts every `starts_at` vs `now()` comparison,
  including the door guard's window.
  Also fixed on the way: `deploy.sh` could leave the site in maintenance mode forever (`set -e`
  after `artisan down`, exiting before `artisan up`) — now trapped.
  **Deviation on record:** the instance sends through **Hostinger SMTP**, which ADR-005 §3
  forbids. Acceptable to bring the instance up; blocking for launch (Gate 9), because a ticket
  in a spam folder is a person at a door without a ticket. The whole ecosystem shares this.
- **2026-07-26** — **Production-mode dry run (pre-deploy).** With `config:cache` +
  `route:cache` + `view:cache` applied, every public surface still answers 200. One trap found
  and documented in `DEPLOYMENT.md`: **`routes/nexo-sso.php` returns early when SSO is off, so
  `route:cache` bakes the absence of those routes into the cache.** Flipping
  `NEXO_SSO_ENABLED=true` in a production `.env` therefore does *nothing* until the routes are
  re-cached — `/auth/nexo/redirect` keeps 404-ing. That is exactly how SSO gets activated in
  task 8.6, so it would have cost real debugging time. `scripts/deploy.sh` re-caches on every
  deploy; the hazard is only hand-editing `.env` between deploys.
- **2026-07-26** — **`audit-open-source`: CLEAN** (task 9.1, pre-public). Full history across
  every commit + HEAD + repo metadata. No secret was ever committed: `.env`, the private brief
  (`nexoevents.md`), `planning-prompt.md` and `CLAUDE.local.md` were **never tracked**, so there
  is nothing to scrub — only `.env.example` ever entered git. The only `secret`-looking matches
  are GitHub Actions `${{ secrets.* }}` references, which are placeholders. No real `APP_KEY`, no
  hosting account, no server path, no customer data. **Fixed here:** `docs/SCOPE.md` linked the
  gitignored brief (`../nexoevents.md`), which would have been a broken link the day the repo went
  public. **Owner-accepted notes:** author email `alvaro@mc4pc.com` appears in every commit;
  `hola@alvarocdev.com` is the intentional public support address; ADR-002 mentions Hostinger's
  generic SSH port (65002) as the *reason* for a design constraint — no account, host or path.
- **2026-07-26** — **Phase 7 done: the tool now meets the full Nexo standard** (164 tests green).
  Report flow + kill-switch commands, cookieless view counters, the whole SEO layer, legal pages,
  cross-tool theme/language, beacon wired off, hardening. Non-obvious things:
  - **The `soon`→`live` launch flip is four surfaces, not one** — see PLAN 9.4. Only the registry
    has a guardian.
  - **Blade components have an isolated scope.** A `$title` or `$noindex` set by a controller does
    NOT reach `partials.head` through `<x-guest-layout>`; it has to be a declared prop and
    forwarded. Every page was silently serving generic metadata until `SeoBaseTest` caught it.
  - **The translation generator skips any all-lowercase key** (`__('entradas')`), treating it as a
    lang-file lookup rather than literal text — so it is never translated. Use a phrase with a
    placeholder: `__(':count entradas', [...])`.
  - **The theme-init CSP hash must be computed from the RENDERED HTML**, not from the Blade file:
    that file's own comment contains the word `<script>`, so a naive regex hashes the wrong bytes.
    `CrossToolPersistenceTest` derives it from the response, so drift fails a test instead of
    blocking the script in production.
  - **Theme and language cookies are exempt from encryption on purpose** (`bootstrap/app.php`).
    Encrypted with this app's key, no sibling tool could read them. In tests use
    `withUnencryptedCookie()` — the plain `withCookie()` helper encrypts, which no sibling could
    produce.
  - **`scripts/race-drill.sh` is the real atomicity proof.** The Pest suite runs on SQLite where
    `lockForUpdate()` is a no-op, so it proves the app logic, not the database guarantee. The
    drill races genuinely parallel processes against MySQL (6 racers → 1 ticket, 6 scans → 1
    entry). Re-run it after touching `EventRegistrar` or `TicketCheckin`.
  - **`lang/en` needs no `passwords.php`/`validation.php`** — English is the framework's own
    fallback (verified by rendering). A previous audit flagged this as a gap; it was not one.
  - Legal pages describe what the code actually does and are **not lawyer-reviewed** — flagged for
    the owner.
- **2026-07-26** — **Phase 6 done: the door scanner uses the camera** (128 tests green). Native
  `BarcodeDetector` where available, `jsQR` otherwise, loaded by dynamic import so its 130 KB is a
  separate chunk only the scanning page fetches. Things that will bite again:
  - **A ticket from another event used to be consumed before being rejected.** `checkInByToken()`
    checked in first and the controller rewrote the answer to "unknown" afterwards — so the
    attendee lost their entry at the event they had actually registered for, and any organizer
    could burn a competitor's tickets by scanning them. The event scope now goes **into** the
    service, before any write. Found by writing `AC-SCAN-2`, not by review.
  - **Never mix Blade's inline `@php(...)` with a `@php ... @endphp` block in one view.** Blade's
    raw-block regex matches from the *first* `@php` to the block's `@endphp` and swallows every
    directive in between; the page 500s with a confusing "unclosed @if". Four views here still use
    the inline form. Arrays for the front end belong in the controller anyway.
  - **The scanner is progressive enhancement, deliberately.** The manual form is server-rendered
    and works with JS off; camera controls stay `hidden` until `scanner.js` confirms a camera API
    exists. An event is a fixed moment — a blank scanner at 8pm cannot be fixed by a deploy, which
    the freeze rule forbids anyway. Do not "simplify" this into a JS-rendered page.
  - `Permissions-Policy` is now `camera=(self)`; `SecurityHeadersTest` asserts it. jsQR was chosen
    over wasm/worker decoders precisely so the CSP needed no `wasm-unsafe-eval` or `worker-src`.
  - **Owner-gated and still open:** the real-device pass (iOS + Android over HTTPS). `AC-SCAN-4`
    (re-arm) and `AC-SCAN-5` (duplicate damping) are camera-in-hand behaviours no PHP test can
    reach; checklist in [docs/specs/SPEC-scanner.md](docs/specs/SPEC-scanner.md).
- **2026-07-26** — **Phase 5 done: the ticket now arrives by email** (117 tests green; Pint +
  Larastan + i18n `--check` clean). Registration queues a `TicketIssued` mail (event summary +
  QR + link); a resend flow recovers a lost ticket; organizers must verify their email before
  publishing. Non-obvious things worth keeping:
  - **The emailed QR is a PNG drawn with GD**, not through `bacon/bacon-qr-code`'s writer — that
    only reaches PNG via `ImagickImageBackEnd`, and Imagick is a PECL extension shared hosting
    may not have. `QrPng` walks the encoder matrix itself; `composer.json` declares `ext-gd`.
  - **Never assert email content against `Mailable::render()`.** That is the *preview* path and
    `Mailer::render()` rewrites every `cid:` into a base64 data URI — asserting there would
    "prove" exactly what ADR-005 §7 forbids (Gmail strips data URIs) while the real mail is
    fine. Tests assert on the sent message via the array transport.
  - **A resend rotates the token** (ADR-008, supersedes ADR-004 §3): only the hash is stored, so
    the original QR is unrecoverable by design. The old QR dies on resend.
  - **Auth mails do not use Laravel's `MailMessage` markdown wrapper.** Its strings come from the
    framework's English translations, which this project's i18n cannot reach (Spanish is the
    source language, the generator translates outward). A Spanish-first product was sending
    "Verify your email address". They now carry `->view('emails.verify-email')`. Returning a
    *Mailable* from `toMail()` instead is a trap: the notification channel does not address it,
    and it fails with "An email must have a To header".
  - **`routes/console.php` scheduled a command that never existed** (`nexo:send-reminders`), so
    every `schedule:run` errored. It now drains the queue, and `AC-QUEUE-1` asserts every
    scheduled command resolves.
  - Local mail goes to the shared Mailpit (`MAIL_MAILER=smtp`, `host.docker.internal:1025`, UI
    at :8025). **Real-inbox deliverability is owner-gated** and still pending — checklist in
    [docs/DELIVERABILITY.md](docs/DELIVERABILITY.md); it needs the provider credentials, which
    never enter the repo.
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
