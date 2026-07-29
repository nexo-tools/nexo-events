# Deployment

Nexo Events runs on shared hosting (Hostinger, LiteSpeed) like its sibling tools. This document is the runbook; the host-specific gotchas live in the `deploy-laravel-hostinger` skill of the standards repo.

> **Not yet deployed.** Target instance: `nexoevents.alvarocdev.com`. First provisioning is PLAN task 8.2.

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
- Override with `DEPLOY_FORCE=1` only when you are deploying the fix *for* the event that is running.

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
2. **`QUEUE_CONNECTION=database`** and the scheduler cron. Without it **no ticket email ever leaves**, while the app looks perfectly healthy:
   ```
   * * * * * cd ~/domains/alvarocdev.com/nexoevents && php artisan schedule:run >> /dev/null 2>&1
   ```
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
