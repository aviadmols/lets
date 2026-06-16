#!/bin/sh
# Pre-deploy guard + migrations. Runs once before the new version takes traffic.
# Keep idempotent and fail fast on dangerous misconfiguration.
set -eu

# === CONSTANTS ===
ENV="${APP_ENV:-production}"
SERVICE="${RAILWAY_SERVICE_NAME:-web}"   # only `web` runs migrations (canonical migrator)

# Refuse SQLite in production — Railway containers have ephemeral filesystems and
# would silently lose all data on restart.
if [ "$ENV" = "production" ] && [ "${DB_CONNECTION:-}" = "sqlite" ]; then
    echo "REFUSING TO DEPLOY: SQLite in production loses data on container restart"
    exit 1
fi

# Required secrets.
[ -z "${APP_KEY:-}" ] && echo "FATAL: APP_KEY missing" && exit 1
[ -z "${APP_URL:-}" ] && echo "FATAL: APP_URL missing" && exit 1
[ -z "${TENANT_CREDENTIALS_KEY:-}" ] && echo "FATAL: TENANT_CREDENTIALS_KEY missing" && exit 1

# Clear any baked config cache (prevents stale encryption keys / env).
rm -f bootstrap/cache/config.php 2>/dev/null || true

# Migrate ONLY from the web service. This script is shared by web, worker, and
# scheduler; running `migrate` from all three concurrently can race (two
# processes creating the same table → one fails the deploy). web is the
# canonical migrator; worker/scheduler skip it. Defaults to running when
# RAILWAY_SERVICE_NAME is unset (local) so local deploys still migrate.
if [ "$SERVICE" = "web" ]; then
    php artisan migrate --force
else
    echo "predeploy: $SERVICE — skipping migrate (web is the canonical migrator)"
fi
# (spatie settings migrations, if any, run as normal migrations above — there is
# no `settings:migrate` command, so it is intentionally not invoked here.)
php artisan config:cache || true
php artisan event:cache || true

echo "predeploy: ok"
