#!/bin/sh
# Pre-deploy guard + migrations. Runs once before the new version takes traffic.
# Keep idempotent and fail fast on dangerous misconfiguration.
set -eu

# === CONSTANTS ===
ENV="${APP_ENV:-production}"
SERVICE="${RAILWAY_SERVICE_NAME:-web}"   # only `web` runs migrations (canonical migrator)
MIGRATION_WAIT_SECONDS=900              # worker/scheduler wait this long for web's migrate

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
    # …but never START new code on a schema web has not migrated yet: a charge
    # or refund that writes a column the database lacks fails. Wait (bounded)
    # until no migration is pending; `--pending=1` exits 1 while any is.
    echo "predeploy: $SERVICE — waiting for web to finish migrating"
    WAITED=0
    until php artisan migrate:status --pending=1 >/dev/null 2>&1; do
        if [ "$WAITED" -ge "$MIGRATION_WAIT_SECONDS" ]; then
            echo "FATAL: migrations still pending after ${MIGRATION_WAIT_SECONDS}s — not starting $SERVICE on an old schema"
            exit 1
        fi
        sleep 10
        WAITED=$((WAITED + 10))
    done
    echo "predeploy: $SERVICE — schema is current"
fi
# (spatie settings migrations, if any, run as normal migrations above — there is
# no `settings:migrate` command, so it is intentionally not invoked here.)
php artisan config:cache || true
php artisan event:cache || true
# Precompile Blade views + cache Filament's components and Blade ICONS. Without this the
# panel re-reads + re-parses every heroicon SVG from disk on EVERY render — the bulk of
# the slow admin page loads under classic FrankenPHP (no persistent worker). Big, safe win.
php artisan view:cache || true
php artisan filament:optimize || true

echo "predeploy: ok"
