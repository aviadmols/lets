#!/bin/sh
# Web service entrypoint. FrankPHP serves Laravel's public/ via the Caddyfile.
set -eu

# Clear stale config cache (prevents encryption-key / env mismatch after deploy).
rm -f bootstrap/cache/config.php 2>/dev/null || true

# Fail loudly if the app key is missing — the app cannot decrypt anything.
if [ -z "${APP_KEY:-}" ]; then
    echo "FATAL: APP_KEY is not set"
    exit 1
fi

# Start FrankPHP using the repo Caddyfile. $PORT is provided by Railway.
exec frankenphp run --config Caddyfile
