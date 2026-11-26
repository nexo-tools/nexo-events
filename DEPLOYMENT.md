# Deployment

Nexo Events runs on shared hosting (Hostinger, LiteSpeed) like its sibling tools. This document is the runbook; the host-specific gotchas live in the `deploy-laravel-hostinger` skill of the standards repo.

> **Live** at `nexoevents.alvarocdev.com` since 2026-07-26. Deploys are **manual on purpose** — see
> [ADR-009](docs/adr/ADR-009-manual-deploy-during-door-windows.md): an automatic deploy could take the
> instance down while people are being scanned in at a door.
> Trigger with `gh workflow run deploy.yml --repo nexo-tools/nexo-events`.

## Running it locally

Before deploying anywhere, this is how to get Nexo Events up on your own machine. The README
points here on purpose: keeping the steps in one place is why they stopped drifting.

### Option A — everything in Docker (recommended if you just want it running)

`compose.yaml` in this repo runs the **app only**: the author's machine keeps a single
MySQL/Mailpit shared by every Nexo tool, so shipping another database per repo would be
waste. `compose.selfhost.yaml` is the overlay that fills the gap for everyone else.

```sh
cp .env.example .env
# in .env: DB_HOST=mysql  DB_PORT=3306  MAIL_HOST=mailpit  MAIL_PORT=1025
docker compose -f compose.yaml -f compose.selfhost.yaml up -d
docker compose exec laravel.test composer install
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate
npm install && npm run build
```

The app answers on **http://localhost:8103** and outgoing mail lands in Mailpit at
http://localhost:8025.

### Option B — your own MySQL

Keep `compose.yaml` alone (or no Docker at all) and point `.env` at your database:
`DB_HOST` / `DB_PORT` / `DB_DATABASE` (`nexo_events`) / `DB_USERNAME` / `DB_PASSWORD`. Everything
else is a stock Laravel app: `composer install`, `php artisan key:generate`,
`php artisan migrate`, `npm run build`, `php artisan serve`.

> The values committed in `.env.example` target the author's shared local stack
> (`host.docker.internal:3307`). Override them — they are a default, not a requirement.

Run the suite with `vendor/bin/pest` (SQLite in memory — it never touches your database).

---

## The rule that makes this app different

**Never deploy while an event is at its door.**

Most web apps can be redeployed at any time; a user who sees an error retries a minute later. This one has moments that cannot be retried: an attendee standing outside a venue with a spinning browser does not come back later, and `php artisan down` is exactly that failure. A rollback does not undo it.

So the rule is enforced by code, not by discipline:

- **`php artisan events:door-guard`** exits non-zero if any *published* event starts within the next 2 hours or started within the last 6 hours (configurable: `NEXO_DOOR_GUARD_BEFORE` / `NEXO_DOOR_GUARD_AFTER`).
- **`scripts/deploy.sh` runs it first**, before `artisan down`, and aborts.
- **The GitHub workflow is `workflow_dispatch` only** — deliberately not on push, unlike the sibling tools, so a merge can never take the instance down mid-event.
- Override with `DEPLOY_FORCE=1` only when you are deploying the fix *for* the event that is running — and write down why, where the team will read it, before running it. The guard watches every organizer's events on the instance, not only yours; an override is a decision about someone else's door too.

```bash
php artisan events:door-guard          # human-readable
php artisan events:door-guard --json   # for pipelines
```

## Deploying

```bash
gh workflow run deploy.yml --repo nexo-tools/nexo-events
```

The workflow builds the assets in CI (no Node on the shared host), rsyncs `public/build/`, then runs `scripts/deploy.sh` over SSH.

**Secrets:** `DEPLOY_KEY`, `DEPLOY_HOST`, `DEPLOY_PORT`, `DEPLOY_USER` at the **org** level, and `DEPLOY_PATH` **per repo** (each tool lives in its own directory).

⚠️ An org secret scoped to *"selected repositories"* expands to an **empty string** in a repo that was not added to it — no warning, no error at that point. It surfaced here as `Bad port ''` from `ssh`, which says nothing about secrets. The workflow now checks all five up front and names the missing ones. If that step fails: *Org settings → Secrets and variables → Actions → the secret → Repository access → add this repo*.

**The workflow updates an existing installation; it does not create one.** Its first SSH step is `cd $DEPLOY_PATH && php artisan …`, so the app must already be cloned and configured there. First provisioning is manual — see below.

## First provisioning

Follow the `deploy-laravel-hostinger` skill. What this app needs on top of the standard sequence:

1. **`.env`** — `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://nexoevents.alvarocdev.com`, `SESSION_SECURE_COOKIE=true`, DB credentials, and the **mail** block (see [docs/DELIVERABILITY.md](docs/DELIVERABILITY.md) — the ticket *is* the email).
2. **`QUEUE_CONNECTION=database`** and the scheduler cron. Without it **no ticket email ever leaves**, while the app looks perfectly healthy — and the pre-event reminders never go out either (`events:send-reminders` rides the same schedule):
   ```
   * * * * * cd ~/domains/alvarocdev.com/nexoevents && php artisan schedule:run >> /dev/null 2>&1
   ```
   The family sender convention (one address per tool) goes in the same `.env`:
   ```
   MAIL_FROM_ADDRESS="nexoevents@alvarocdev.com"
   MAIL_FROM_NAME="Nexo Events"
   ```
   No mailable sets its own `from`, so this is the single place that decides who the mail comes from — `MailStandardTest` keeps it that way.
3. **`php artisan migrate --force`**, then the storage symlink by hand (`exec()` is disabled, so `storage:link` fails).
4. **Re-assert the CSP in `public/.htaccess`** — LiteSpeed's "Force HTTPS" overwrites the header the middleware sends. The file already contains the policy; `SecurityHeadersTest` keeps it byte-identical to the middleware, so never edit one without the other.
5. **Verify** with the smoke checks below.

## Flipping an env flag is not enough: re-cache the routes

`routes/nexo-sso.php` returns early when `NEXO_SSO_ENABLED` is false, so the SSO routes are
**not registered at all**. `php artisan route:cache` then bakes that absence into the cached
route file. Consequence, verified locally:

> Setting `NEXO_SSO_ENABLED=true` in production changes **nothing** until the routes are
> re-cached. `/auth/nexo/redirect` keeps 404-ing and the login button leads nowhere.

The same applies to the beacon (`NEXO_BEACON_ENABLED`) for config caching. So after editing
`.env` in production, always:

```bash
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

`scripts/deploy.sh` already does this on every deploy — the trap is only when you edit `.env`
by hand *between* deploys, which is exactly how SSO gets switched on (PLAN task 8.6).

The integration API below is deliberately built the other way round — its routes are
**always** registered and the flag is checked at request time — precisely so that turning it
on is one `.env` edit and a `config:cache`, with no route surprise. Re-caching after any
`.env` edit is still the rule.

## Nexo ID controls the session (`NEXO_AUTH_MODE=sso`)

Since PLAN 10.25 (template `nexo-sso-client` v2.1) Nexo ID owns the organizer session:
back-channel logout, a re-check with Nexo ID every `NEXO_SSO_RECHECK_MINUTES` (page loads
only — the door scanner's JSON check-in is never bounced) and, in `sso` mode, no local
password, sign-up or reset at all. Contract and reasons: [SPEC-nexo-session](docs/specs/SPEC-nexo-session.md).

| Variable | Hosted instance | Default | Effect |
|---|---|---|---|
| `NEXO_AUTH_MODE` | `sso` | `local` | `local` · `transition` · `sso` (only with `NEXO_SSO_ENABLED=true`) |
| `NEXO_AUTH_TRANSITION_UNTIL` | empty | empty | last day of `transition` |
| `NEXO_SSO_BACKCHANNEL` | `true` | `true` | `false` → the back-channel endpoint answers 501 |
| `NEXO_SSO_RECHECK_MINUTES` | `15` | `15` | `0` = off; fail-open if Nexo ID is down |
| `NEXO_SSO_POST_LOGOUT_REDIRECT_URI` | empty | empty | empty = `{APP_URL}/auth/nexo/signed-out` |

On the Nexo ID side (operator), once this deploy is live:

```bash
php artisan nexo:sso-client:update <client_id> \
    --add-redirect=https://nexoevents.alvarocdev.com/auth/nexo/signed-out \
    --backchannel=https://nexoevents.alvarocdev.com/auth/nexo/backchannel-logout
```

Before flipping to `sso`, the people who run a door must be able to sign in to Nexo ID with
the **same email** as their local account: that account joins itself on the first Nexo ID
sign-in. A password session open when the flag flips is closed on its next request (the
scanner says «Tu sesión se cerró» and «Volver a entrar» goes through Nexo ID back to the
door), so the flip respects the door window like any deploy (`events:door-guard`).
The migration `nexo_sso_revocations` is additive; until it runs the guard fails open.

## Integration API (optional, off by default)

Lets an organizer connect an external system — their own website, typically — to their
account with a scoped, revocable token, so it can create and publish **its own** events and
get the public `/e/{slug}` URL back. Off unless you turn it on; nothing about a standalone
instance changes while it is off, panel included. Design and rationale: [ADR-010](docs/adr/ADR-010-integration-api.md),
contract: [SPEC-integration-api](docs/specs/SPEC-integration-api.md).

| Variable | Default | What it does |
| --- | --- | --- |
| `NEXO_API_ENABLED` | `false` | Master switch. While false, every `/api/v1/*` request answers **404** and `/app/integraciones` does not exist. |
| `NEXO_API_RATE_READ` | `60` | Read requests per minute **per token**. |
| `NEXO_API_RATE_WRITE` | `20` | Write requests per minute per token (create, edit, publish, close, cancel, invite and revoke collaborators). |

Turning it on:

```bash
# in .env
NEXO_API_ENABLED=true
php artisan config:cache        # the routes are already there; only config caching matters
```

Then, as the organizer: **Integrations** in the panel → name the system, pick what it may
do, copy the token **once** (only its sha256 is stored, so it cannot be shown again) → paste
it into the other system. `GET /api/v1/me` is the handshake to confirm the link, and it
reports `email_verified`, which publishing requires (ADR-007).

Scopes, in the order the panel offers them: `events:read`, `events:write` and — unticked by
default, with a warning — `collaborators:manage` (v1.1), which lets the token invite, list
and revoke collaborators **of the events it created** (`external_ref` set). A consumer that
hands ticket management to other people (a venue inviting the band) needs all three: turn
the flag on, `config:cache`, then issue the token with the three boxes ticked.

Two guarantees worth stating to whoever asks for the integration: **no API answer carries
attendee data** — no names, no emails, no ticket tokens, ever — and revoking a token from
the panel takes effect on the very next request. What `collaborators:manage` adds, said
plainly: a person the token invites can, after accepting with a verified account of that
email, see the **names and status** of the registrants of that event (not their emails)
until the access is removed or the event is a week old; the owner is emailed for every such
invitation (ADR-011, and the amendment to ADR-010 §4).

## Attendee phone (optional, off by default)

`NEXO_ATTENDEE_PHONE` decides whether the public registration form also asks for a
WhatsApp or phone number (PLAN 10.13, ADR-003 amendment 2026-11-21).

| Variable | Default | What it does |
| --- | --- | --- |
| `NEXO_ATTENDEE_PHONE` | `off` | `off`: name and email only, and a phone posted anyway is ignored. `optional`: the field appears after the email, never required. Any other value reads as `off`. |
| `NEXO_ATTENDEE_PHONE_COUNTRY` | `AR` | The country the phone's selector starts on (ISO code, PLAN 10.16). A number typed without `+` is read as one of this country's; people elsewhere pick theirs. An unknown code reads as `AR`. |

Only the **event owner** sees the number, next to the email in the registrations list:
formatted the way its country writes it (`+54 9 11 2345-6789`), as a `tel:` link, with a
WhatsApp link unless it is a plain landline, and the country named when it is not the
instance's. Collaborators, emails, public pages and the integration API never carry it, and
the privacy page describes it only while the switch is `optional`. Turning it on is an
`.env` edit plus `php artisan config:cache`.

## Post-deploy smoke

```bash
BASE=https://nexoevents.alvarocdev.com
curl -s -o /dev/null -w "%{http_code}\n" $BASE/                       # 200
curl -sI $BASE | grep -i content-security-policy                      # the strict one, not upgrade-insecure-requests
for f in favicon.ico og-image.png robots.txt sitemap.xml up; do
  printf "%s %s\n" "$f" "$(curl -s -o /dev/null -w '%{http_code}' $BASE/$f)"
done
```

Then the flow that actually matters, end to end on the live instance: create an event → register with a real address → **the ticket email arrives with a scannable QR** → open the scanner on a phone → scan → green → rescan → red. Nothing else proves the deploy worked.

## Operating during an event

- Check uptime monitoring **before** door time, not after.
- Keep `events:door-guard` honest: if an organizer changes an event's start time, the window moves with it.
- The kill-switch is available at any moment and needs no deploy: `php artisan events:kill <slug> --reason="…"` / `php artisan events:restore <slug>`.
- Watch the mail provider's daily cap on announcement days (docs/DELIVERABILITY.md).
