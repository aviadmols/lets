#!/bin/sh
# Pre-deploy guard + migrations. Runs once before the new version takes traffic.
# Keep idempotent and fail fast on dangerous misconfiguration.
set -eu

# === CONSTANTS ===
ENV="${APP_ENV:-production}"

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

# Migrate, then re-cache for runtime performance.
php artisan migrate --force
php artisan settings:migrate || true
php artisan config:cache || true
php artisan event:cache || true

echo "predeploy: ok"
