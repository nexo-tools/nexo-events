#!/usr/bin/env bash
# Server-side deploy helper: run from the app root over SSH.
#
# Nexo Events differs from its sibling tools in one way that matters: it has
# moments that cannot be retried. `artisan down` while an event is at its door
# leaves people standing outside a venue, and no rollback fixes that after the
# fact. So the deploy asks the app whether it is safe FIRST, and refuses itself.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ "${DEPLOY_FORCE:-0}" != "1" ]; then
    if ! php artisan events:door-guard; then
        echo
        echo "Deploy aborted by the door guard. Override with DEPLOY_FORCE=1 only if you"
        echo "are certain (e.g. deploying the fix FOR the event that is running)."
        exit 1
    fi
else
    echo "⚠ DEPLOY_FORCE=1 — door guard bypassed deliberately."
fi

# From here on the app is dark, so ANY failure must bring it back. Without this,
# `set -e` aborting on a bad migration (or a wrong password, or a full disk) exits
# before `artisan up` and leaves the site in maintenance mode indefinitely — the
# exact state this app exists to avoid, reached by the deploy meant to improve it.
cleanup() {
    code=$?
    if [ "$code" -ne 0 ]; then
        echo
        echo "✗ Deploy failed (exit $code). Bringing the app back up rather than leaving it dark."
        php artisan up || true
    fi
    exit "$code"
}
trap cleanup EXIT

php artisan down --retry=30 || true

git pull origin main
# --no-scripts: shared hosts often disable proc_open, which Composer needs to
# run post-install scripts; we run package:discover directly.
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
php artisan package:discover --ansi
php artisan migrate --force
# Instance identity for the legal pages (nexo-ui standard). These are NOT secrets
# — a name and a public contact address — but they are per-instance: the repo
# cannot ship them, because .env.example is what a self-hoster copies and they
# would inherit the upstream author as their data controller. So they arrive as
# GitHub org variables and get written here, idempotently, before config:cache.
upsert_env() {
  local key="$1" value="$2"
  [ -z "$value" ] && return 0
  if grep -q "^${key}=" .env; then
    # Rewrite in place without a temp file the web server could serve.
    sed -i.bak "s|^${key}=.*|${key}=\"${value}\"|" .env && rm -f .env.bak
  else
    printf '\n%s="%s"\n' "$key" "$value" >> .env
  fi
}
upsert_env NEXO_LEGAL_OPERATOR "${NEXO_LEGAL_OPERATOR:-}"
upsert_env NEXO_LEGAL_CONTACT "${NEXO_LEGAL_CONTACT:-}"

php artisan config:cache
php artisan route:cache
php artisan view:cache
# Flush the rendered-page cache: it bakes in content-hashed @vite asset URLs, and
# a fresh public/build changes those hashes — stale entries would 404 the CSS.
php artisan cache:clear

php artisan up

echo "✓ Deployed $(git rev-parse --short HEAD)"
