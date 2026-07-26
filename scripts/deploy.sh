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

php artisan down --retry=30 || true

git pull origin main
# --no-scripts: shared hosts often disable proc_open, which Composer needs to
# run post-install scripts; we run package:discover directly.
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
php artisan package:discover --ansi
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
# Flush the rendered-page cache: it bakes in content-hashed @vite asset URLs, and
# a fresh public/build changes those hashes — stale entries would 404 the CSS.
php artisan cache:clear

php artisan up

echo "✓ Deployed $(git rev-parse --short HEAD)"
