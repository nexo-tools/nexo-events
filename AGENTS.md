# Nexo Events

> Entry point for any AI/agent working on this project. It follows Alvaro's standards system (repo `alvaro`, alvarocdev.com). Keep this file updated: persist here the important context that comes up during work sessions.
> This repo will be public: no secrets, credentials, or sensitive infrastructure details here.

## What this project is

Free event registration and QR ticketing of the Nexo ecosystem: anyone creates an event, people register with just an email, receive a QR ticket, and the organizer validates entry at the door by scanning with their phone. Open source, multi-instance, self-hostable, cookieless — like its siblings (Nexo Links, Nexo Agenda, Nexo Short, Nexo ID). **Current state: LIVE at https://nexoevents.alvarocdev.com, feature-complete, not yet announced.** Phases 5–8 are closed (email, camera check-in, anti-abuse, SEO, legal pages, production, ops); what remains is the ecosystem `soon`→`live` flip and the announcement itself. Start at [docs/PLAN.md](docs/PLAN.md), which carries the executed history, the verified gap register and the phase in progress.

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

- **2026-11-26** — **Unified Nexo experience, this tool's own fixes (PLAN 10.17–10.23,
  [SPEC-event-panel](docs/specs/SPEC-event-panel.md)).** The header, account menu, login and
  tab titles are NOT here: they arrive with the shared chrome/session v2. What a future session
  should not have to rediscover:
  - **The operator mail no longer fires on a retried job attempt** (10.17,
    `App\Support\OperatorAlerts`, from `templates/nexo-ops`). On 2026-11-23 one SMTP
    `TransportException` on a ticket mail, delivered by the next attempt, mailed the operator.
    The worker fires `JobExceptionOccurred` before it reports an attempt's exception, so the
    exception object is remembered (a `WeakMap`) and its report skipped; `JobFailed` mails once.
    The listeners are wired in `->booted()`, not in the exceptions callback, which only runs once
    the handler is resolved. `OperatorAlertTest` drives a real `queue:work --once`.
  - **Every event opens on its summary** (`events.manage`, 10.18), for the owner too, under one
    partial (`app/events/_header`) with Summary · Registrations · Door · Edit. Collaborators
    live on the summary now (owner only). A new event screen includes that partial; the
    routes did not change.
  - **No confirmation had ever run** (10.19). The CSP allows no inline script, so every
    `onsubmit="return confirm(…)"` was refused by the browser and "Cancel event" (which mails
    every ticket holder), "Release spot", "Remove access" and "Revoke" submitted without asking.
    Destructive forms declare `data-confirm`; `app.js` asks in the capture phase, before the
    double-submit guard. `EventDraftTest` fails on any `on…=` attribute in a view.
  - **Cancelling only acts on published/closed** (10.19): a cancelled event shows its public
    page, so cancelling a draft used to publish it and cancelling a blocked one undid the
    kill-switch. Drafts are deleted instead (`EventLifecycle::deleteDraft`, no API endpoint).
  - **`EventRequest` has the capacity floor** of the summary's form (10.21), web and API
    `PATCH`, only when the value changes (older rows under it stay editable).
  - **The scanner posts with `redirect: 'manual'`** (10.23): a signed-out JSON request on a web
    route is answered with a redirect to `/login`, and following it ended in "No connection".
    A 401, 419 or unfollowed redirect is "the session ended" (`outcomeOf()`, node test in
    `tests/js/`); the door page is unchanged otherwise.
  - Spanish says «Cupo» (never «Aforo»), Portuguese «Vagas» (10.20, `CapacityWordingTest`).
  - `php artisan test --parallel` is flaky here (the route:cache guardian races the other
    processes for `bootstrap/cache/routes-v7.php`); the serial suite takes ~10 s — use it.

- **2026-11-23** — **The attendee phone gets a country and a format (PLAN 10.16).** The owner
  saw "+54 …" numbers with a WhatsApp link and "11 …" ones as bare text, side by side. What a
  future session should not have to rediscover:
  - **libphonenumber-lite is the only reader of numbers** (`App\Support\AttendeePhone`):
    `parse()` demands a *valid* number (area code and length the country hands out), not a
    *possible* one — that is what refuses "15 2345-6789" without an area code.
  - **An Argentine mobile typed without 9/15 parses as a landline** (`FIXED_LINE`,
    `+54 11 …`). `present()` shows and links it as `+54 9 …` — the owner's decision; WhatsApp
    only finds Argentine mobiles with the 9. Storage keeps what was typed (E.164 without the
    9), so the rule lives in one display method and can be revisited.
  - **Stored as E.164 since 10.16; older rows are raw** and read with the instance country
    on display (no migration). Unreadable leftovers show as typed, `tel:` only.
  - **Country names are generated files** (`lang/{en,es,pt}/countries.php`,
    `scripts/generate-countries.php`, run in the Sail container — it needs `ext-intl`, the
    app does not). Re-run it only if AC-TICKET-27 says a code is no longer supported.
  - Locked at 9.0.43 on purpose (newest release ≥ 2 weeks old, 2026-11-23); `^9.0` lets the
    usual dependency updates move it. No migration, no new env var required in production
    (`NEXO_ATTENDEE_PHONE_COUNTRY` defaults to `AR`).

- **2026-11-22** — **Optional "More information" link per event, API v1.3 (PLAN 10.15),
  deployed 2026-11-22.** The venue's site was pasting "Más info y flyer: https://…" into the
  description, where `/e/{slug}` prints it as untappable text. What a future session should
  not have to rediscover:
  - **`events.info_url` is public, but only on `/e/{slug}`** — not on the ticket, the emails,
    the meta, the JSON-LD or the sitemap (AC-TICKET-23 counts exactly one occurrence on the
    page). Opposite of `access_address`, same "one surface" discipline.
  - **Three properties keep it from being a phishing aid, each with a test that was turned
    red on purpose**: `url:https` in `EventRequest` (web and API), the domain printed on the
    button (`Event::infoUrlHost()`: lower case, no `www.`, punycode for non-ASCII hosts) and
    `rel="noopener noreferrer nofollow ugc"`. `infoUrlHost()` also re-checks the scheme, so a
    value that reached the column some other way renders nothing.
  - **Laravel's `url` rule rejects `"` and `<` but accepts `'`**, upper-case `HTTPS://`,
    credentials (`https://a@b`) and Cyrillic hosts. That is why the host shown is the parsed
    one, converted to punycode — and the punycode step needs `ext-intl` (in CI and the dev
    container; not declared in `composer.json`, not verified on the production host).
  - No new Tailwind/CSS: the button is `nexo-btn nexo-btn--ghost` plus utilities; CI builds
    `public/build` from source on every deploy anyway.
  - Migration rehearsed on MariaDB 11.8.9 (up, rollback with data, up). The API consumer
    sends `https://laherreriafloresta.com.ar/evento/<slug>`.

- **2026-11-21** — **Optional attendee phone and private address (PLAN 10.13–10.14), built
  locally, not deployed.** Two asks from the same venue, both invisible to an instance that
  does not use them. What a future session should not have to rediscover:
  - **`NEXO_ATTENDEE_PHONE` is `off` unless it is exactly `optional`.** With it off the
    request has no `phone` rule and `phone()` returns null, so a posted number is dropped,
    not stored. The number is owner-only: it rides the same `showEmails` condition as the
    email in the registrations list, and AC-API-11 asserts it never reaches the API. There
    is no purge/anonymization job anywhere: tickets cascade with their event.
  - **`access_address` is private on the same terms as `access_info`** (ADR-012 amendment):
    the never-public assertions of both live in one test (AC-TICKET-1/17). The copy button
    is the existing `data-copy` handler in `app.js`, now reverting its label after 2 s.
  - **After a deliberate violation in a Blade view, run `php artisan view:clear`.** Restoring
    the file within the same second as the compile left a stale compiled view, and a green
    test went red for no code reason.
  - Production needs `NEXO_ATTENDEE_PHONE=optional` in `.env` (+ `config:cache`) for the
    phone; the address needs nothing but the deploy.
- **2026-11-21** — **Pre-deploy pass for the 10.1–10.12 blocks.** Three things a future session
  should not have to rediscover:
  - **Production runs MariaDB 11.8, not MySQL.** `DB_CONNECTION=mysql` on the server, but the
    engine is MariaDB (11.8.9 on 2026-11-21); the tests run on SQLite and the shared dev env is
    MySQL 8.4, so neither exercises a migration on the real engine. Before a deploy that carries
    migrations, rehearse them on a throwaway `mariadb:11.8` (up, rollback with data, up again).
    Done for the four of 10.2/10.8/10.9 on 2026-11-21: clean.
  - **`composer audit` turns red on its own** when advisories land on a lock nobody touched (29
    in 9 packages between August and November). Updated with `--minimal-changes` and guzzle held
    at `^7` (12 packages, no major jumps, Laravel 13.20 → 13.43); a plain `-W` update had pulled
    guzzle 8 and `brick/math` 1.0 for nothing.
  - **Organizer sign-up now has the honeypot** every other public form had (AC-ABUSE-6): scripted
    sign-ups with third parties' addresses had been making the instance mail them since
    2026-10-28. The unverified accounts they left in production (89 counted on 2026-11-12) were
    not cleaned up by this change.
- **2026-10-28** — **Event collaborators, ticket-holder info and Integration API v1.1 built
  (PLAN 10.7–10.12), not yet deployed.** Same first consumer (a venue whose bands manage the
  entries of their nights). Governing docs: [ADR-011](docs/adr/ADR-011-event-collaborators.md),
  [ADR-012](docs/adr/ADR-012-ticket-holder-info-and-payment-text.md), the ADR-010 §4
  amendment, SPEC-event-collaborators, SPEC-ticket-info, SPEC-integration-api v1.1.
  287 → 344 tests, identical with `NEXO_API_ENABLED` off and on. The eight deliberate
  violations of Gate 10-API v1.1 were run locally on 2026-10-29, each turning its named test
  red. What a future session should not have to rediscover:
  - **Managed vs owned.** `Event::canBeManagedBy()` (owner, or an accepted, unrevoked
    collaborator while `now < starts_at + 7 days`) guards registrations, close, reopen,
    capacity, "Ticket management", the scanner, both check-ins and "release a spot".
    Everything else — edit, update, publish a draft, cancel, collaborators — still compares
    `organizer_id` directly. A new route has to pick one on purpose.
  - **Acceptance needs the same email AND a verified account.** The invitation link is shown
    once to whoever invites (flash / `invite_url` in the API `POST`) so it can go by chat;
    that is only safe because an unverified account with the invited email cannot accept. Do
    not drop the verification check without also dropping the shared link.
  - **`expires_at = max(now + 7d, starts_at + 1d)`.** A plain "+7 days" leaves the venue's
    door account without access on show night when the invitation went out weeks ahead.
  - **The traceability guardian now reads three SPECs** (`traceableSpecs()` in
    `ApiSpecTraceabilityTest`). A criterion is declared by writing it in **bold**; planned
    ones are written without bold and with the task number, and go bold in the commit that
    adds their tests. That is how a SPEC lands before its code without the suite going red
    and without loosening the guardian.
  - **`collaborators:manage` only reaches events with `external_ref`.** An event the owner made
    by hand is never opened up by a token. Every invitation made through a token mails the
    owner (`CollaboratorInvitedByIntegration`) and records the token name in `invited_via`.
  - **Release a spot is a conditional update** (`status = valid` and no checkin row), so it
    cannot race a check-in into undoing an entry. The released person's `/t/{token}` still
    renders, says the spot was released and shows no QR (10.12, AC-TICKET-8); the door answers
    `revoked`; no email goes to the person.
  - **`access_info` is "not published", not "secret"** — one free registration away. It never
    reaches `/e/{slug}`, the meta description, the JSON-LD or the sitemap (asserted). With
    `payment_info` set the JSON-LD drops `price`/`priceCurrency`; without it, v1 bytes.
  - Web routes never render JSON (`shouldRenderJsonWhen` is `api/*` only): a validation
    failure on `/app/...` is a redirect with errors, also for `postJson` in tests.
  - Deploy is owner-gated and goes in its own block after the 10.1–10.6 one (ADR-009 door
    windows; `DEPLOY_FORCE` only with a written reason). Real-phone pass of the new screens
    at 390 px is owner-gated too.
- **2026-08-13** — **Integration API v1 built (PLAN 10.1–10.6), off by default, not yet
  deployed.** An organizer can link an external system to their account with a scoped,
  revocable token and let it create/read/update/publish its own events; the first consumer
  is a real venue's Next.js site. `NEXO_API_ENABLED=false` everywhere until an operator says
  otherwise. Governing docs: [ADR-010](docs/adr/ADR-010-integration-api.md) +
  [SPEC-integration-api](docs/specs/SPEC-integration-api.md). 238 → 287 tests.
  What a future session should not have to rediscover:
  - **The env gate is in the middleware, never around the route registration**, and there is
    now a test that boots an app from the `route:cache`d file to prove it (404 with the flag
    off, 401 — reached auth — with it on). Verified to fail if `routes/api.php` is wrapped in
    the `if (! config(...)) return;` shape that `routes/nexo-sso.php` uses. That shape is the
    trap already documented in DEPLOYMENT.md; the API is deliberately the counter-example.
  - **Middleware order is not the order written on the route.** Laravel sorts a route's
    middleware by its priority list, and `ThrottleRequests` is on it — so `AuthenticateApiToken`
    is prepended to that list in `bootstrap/app.php`. Without it the throttle runs first: a
    disabled API answers 429 instead of 404, and the limiter keys by IP instead of by token.
    Asserted by test, and the test was checked to fail with the line removed.
  - **MySQL 8.4 drops the auto-created foreign-key index** when a new index leads with the
    same column. Adding `unique(organizer_id, external_ref)` made that unique the FK's index,
    so dropping it in `down()` failed with errno 1553; the migration now removes and re-adds
    the foreign key. Found by actually running the rollback, which is why DATABASE-STANDARD
    §11 asks for both a written plan *and* an executed one.
  - **`starts_at` is normalized to the instance timezone at the API boundary.** Eloquent
    persists the wall time of whatever offset it is handed, so `...T00:00:00Z` would have been
    stored as `00:00` local — three hours off in Buenos Aires, and invisible, because the event
    page shows what was stored.
  - **One presenter for every event answer**, so "no attendee PII crosses the API" is a
    property of a single function; a test asserts it against a real registration on all six
    endpoints. Attendee data is out of scope by design, not by omission.
  - The token panel (`/app/integraciones`) rides the same flag: with the API off it 404s and
    the dashboard shows no link. Operational consequence, accepted: the first token can only
    be issued once the flag is on.
- **2026-08-02** — **Ola de estandarización transversal (correo, DB, ops, UI).** El correo pasó a la plantilla de familia (`templates/nexo-mail`, registrada con `Blade::anonymousComponentPath` en `AppServiceProvider`): los cuatro mails que había más tres nuevos — cancelación de evento a quien tenga entrada, recordatorio previo (`events:send-reminders`, horario, idempotente por `tickets.reminded_at`, **sin QR a propósito**: solo se guarda el hash del token, así que meterlo obligaría a rotarlo y matar la entrada que la persona ya tiene) y los dos avisos de seguridad de la familia. `tickets.locale` guarda el idioma del registro porque el mail sale desde una cola o desde la sesión del organizador, donde no hay request. Guardianes nuevos: `MailStandardTest`, `DatabaseStandardTest`, `OperatorAlertTest`, `RateLimitTest`, `AuthChromeTest`, y `HelpAndThemeTest`/`StaticPagesTest` endurecidos. Un 500 ahora le manda mail al operador (dedupe 15 min, `NEXO_OPS_MAIL`). 197 → 239 tests.
- **2026-07-29** — **Translation keys flipped from Spanish to English** (190 tests green), matching
  nexoid/nexoshort/nexolinks and the decision recorded in `alvaro/templates/nexo-ui/STANDARD.md`.
  What this changes for anyone writing a new string:
  - **Write `__('…')` in English.** `scripts/translations/{es,pt}.json` are English-keyed maps;
    `lang/{es,pt}.json` are generated from them. There is no `lang/en.json` and there must not be
    — English is the source, so a missing translation falls back to the literal key, which reads.
  - **`APP_FALLBACK_LOCALE=en` while `APP_LOCALE=es`.** The product still speaks Spanish by
    default; the fallback only decides what a *missing* key looks like (English, not raw key).
  - **The Spanish is tuteo neutro, not voseo** — the whole ecosystem ships one register, so
    "Restablece tu contraseña", never "Restablecé". This covers `lang/es/*.php` too.
  - The two login labels that both translated to "Sign in" ("Entrar", "Inicia sesión") were split
    into `Log in` (submit button) and `Sign in` (heading/link); collapsing them would have made
    the button inherit the heading's longer Spanish.
  - The **all-lowercase-key rule is unchanged and now easier to trip**, because English words are
    short: `__('tickets')` is read as a lang-file lookup, not literal text, and is never
    translated. Use `trans_choice('app.tickets', …)` or a phrase with a placeholder.
- **2026-07-28** — **Three nexo-ui guardians adopted** (`BrandAssetsPresentTest`, `DarkModeCoverageTest`,
  `StaticPagesTest` in `tests/Feature/Nexo/`; 184 tests green). The error pages and the legal pages
  already satisfied them — this repo is the ecosystem's reference for both — but the guardians
  themselves needed three fixes, all of which belong upstream in
  `alvaro/templates/nexo-ui/guardians/`:
  - **`expect(...)->toContain($needle, $message)` is a silent trap.** Pest's `toContain()` is
    *variadic*: the second argument is another needle, so the "message" is asserted as page
    content. It fails on healthy code and, under `->not->`, it weakens the check instead.
    Messages go through `toBeTrue()`/`toBeFalse()`/`toBe()`, which do take one.
  - **`$this->get('/favicon.svg')` can never pass.** `public/` is served by the web server, not by
    the router, so static assets 404 inside the test kernel. That test now asserts the *wiring*:
    the head links `/favicon.svg` and `og:image` points at `/og-image.png`.
  - **The dark-mode regex flagged the fix it asks for.** `bg-white … dark:bg-white` matched on the
    `dark:` variant itself (nothing follows it before the closing quote), so a deliberate
    always-light surface could not be expressed. A `(?<!dark:)` lookbehind fixes it.
  Also: the template scans a hand-picked `$keyViews` list (welcome + components + errors), which
  passes vacuously — here it scans **all** of `resources/views` except `emails/` (mail clients
  strip `<style>`, so transactional mail is light-only by design, same carve-out as
  `NoHardcodedColorsTest`). That widened scan found one real offender: the **ticket QR quiet zone**,
  which is `bg-white dark:bg-white` on purpose — `QrSvg` paints black modules and a QR on a dark
  surface does not scan at the door.
- **2026-07-27** — **v1 COMPLETE. Phase 9 closed.** Flipped to `live` across the ecosystem.
  Worth knowing for the next tool that launches: the five *sibling* registries and alvarocdev had
  already been flipped, so the only stale one was **this repo's own** — it described every tool
  correctly except itself, and also still called nexoshort `soon`. A registry is the file nobody
  re-reads after writing. The README carried the same drift in two places (its own row in the
  ecosystem table, and a Status section still saying "not yet deployed" three days after launch).
  Launch form per D5: **no announcement** — being `live` in the hub and every app-switcher is the
  launch. The smoke event was **unpublished** so a live site does not get "SMOKE — prueba de
  despliegue" indexed; the organizer account `smoke@alvarocdev.com` has no usable password by
  design (random, never stored), so it needs a password reset or deletion rather than a login.
- **2026-07-27** — **Phase 8 closed (8.5–8.7).** Anti-abuse drilled on the live instance
  (report → kill → restore, rate limiter cutting at the 5th attempt) and the **double-scan guard
  proven in production**: the same ticket scanned twice returns `ok` then `already`.
  **SSO + beacon activated.** nexoid's prod `issuer` confirmed **https** first — the precondition
  open since planning. Two things future activations must not miss: the client needs **both**
  redirect URIs registered (callback *and* post-logout, or the provider refuses the return), and
  **routes must be re-cached** or the env flag does nothing. Silent SSO verified to fire on normal
  pages while **every attendee surface stays untouched** — `/e/*` and `/t/*` never bounce, which is
  the whole point: an attendee at the door has no account to sign into.
  Security/perf audit clean. Sibling fixes made along the way: the nightly backup now handles
  **SQLite** apps (nexo-tools had failed every night since deploy and had never been backed up —
  the bug was in the script, not the app, and its rotation only ever deleted `*.sql.gz`), and
  `SESSION_SECURE_COOKIE` was added to **nexo-tools** and **nexo-links**, which were serving
  session cookies without it over HTTPS.
- **2026-07-26** — **Backups verified + restore drilled.** The cross-tool `~/backups/bin/nexo-backup.sh`
  auto-discovers apps by scanning `~/domains/*/*/.env`, so this app was picked up with no change.
  A **real restore** was run against the production database and row counts matched — done at the
  one moment it is free of risk, while the data is all test data. Uptime monitoring is still open.
  **Neighbour finding, not ours to fix:** `nexo-tools` FAILS in that backup every single night and
  has never been backed up — its production `.env` still carries dev values (`DB_DATABASE` pointing
  at a SQLite file, `DB_USERNAME=sail`), so `mysqldump` is denied. Worth a look; that is the hub.
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
  **Deviation on record — RESOLVED 2026-07-27, do not re-open:** the instance sends through
  **Hostinger SMTP**, which ADR-005 §3 forbids. It was a launch blocker until it was measured on
  the live instance: a real ticket reached a Gmail inbox with **SPF, DKIM and DMARC all passing**,
  because Hostinger relays through MailChannels and signs with the domain key
  ([docs/DELIVERABILITY.md](docs/DELIVERABILITY.md)). ADR-005 §3 assumed an unauthenticated shared
  IP, which is not what this host does — so the deviation stands, accepted, and Gate 9 closed on
  the measurement. What remains is a **volume** risk, not a deliverability one: the mailbox has a
  daily sending cap and no bounce visibility. Migrate to a relay when volume justifies it.
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
