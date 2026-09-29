# FrankPHP (PHP 8.4) image for the web service. Worker/scheduler services reuse
# this same image and override the start command via the Procfile.
#
# NOT changed here (LOW-2, 2026-09 audit): running as non-root. FrankenPHP's own
# non-root recipe needs Caddy's autosave state + certificate storage moved to a
# writable path via XDG_CONFIG_HOME/XDG_DATA_HOME, plus storage/bootstrap/cache
# ownership fixed up at build time — none of that has been verified to still
# boot cleanly under Railway's container runtime (which also decides $PORT and
# the read/write layout at deploy time, not at this build). Getting that wrong
# ships a container that never serves a single request, which is worse than
# staying root for now. Do this deliberately, with a real Railway deploy to
# verify against, not as a drive-by line here.
FROM dunglas/frankenphp:1-php8.4

# System packages required by the PHP extensions below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libpq-dev libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions. pdo_pgsql (Postgres), redis (queue/cache/Horizon), the rest
# are Laravel/Filament essentials. opcache for production throughput.
# Bundled PHP extensions — compiled from the PHP source already in the image, so
# they have NO network dependency and build deterministically.
RUN install-php-extensions intl zip pdo_pgsql gd bcmath pcntl sockets opcache

# redis (phpredis) built from its GitHub SOURCE — deliberately NOT via PECL.
# pecl.php.net was returning "504 Gateway Timeout" and then hanging for ~50min,
# which failed EVERY build and froze prod on an old image. GitHub is reliable, so
# this drops the pecl.php.net dependency entirely. Build deps are installed only for
# the compile. Pinned for reproducibility (6.2.0 supports PHP 8.4 / ZTS).
ARG PHPREDIS_VERSION=6.2.0
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl $PHPIZE_DEPS; \
    curl -fsSL "https://github.com/phpredis/phpredis/archive/refs/tags/${PHPREDIS_VERSION}.tar.gz" -o /tmp/phpredis.tgz; \
    mkdir -p /usr/src/phpredis; \
    tar -xf /tmp/phpredis.tgz -C /usr/src/phpredis --strip-components=1; \
    cd /usr/src/phpredis; \
    phpize; \
    ./configure; \
    make -j"$(nproc)"; \
    make install; \
    docker-php-ext-enable redis; \
    php -m | grep -qi '^redis$'; \
    cd /; rm -rf /usr/src/phpredis /tmp/phpredis.tgz /var/lib/apt/lists/*

# OPcache production tuning. WITHOUT this the default 10k-file limit thrashes on a
# ~20k-file Laravel+Filament app, recompiling a big chunk of the codebase on every
# request (the bulk of the slow admin page loads). See docker/opcache.ini.
COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

# Runtime hardening (LOW-2, 2026-09 audit): no X-Powered-By/version fingerprint,
# no dev-facing error display even if something upstream of APP_DEBUG misfires.
# See docker/hardening.ini for the reasoning per directive.
COPY docker/hardening.ini /usr/local/etc/php/conf.d/zz-hardening.ini

# Composer from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps first (better layer caching). No `|| true`: a failed install
# must fail the build, not silently ship an image with a stale/broken vendor/
# (LOW-2, 2026-09 audit).
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

# App source.
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Storage + cache must be writable.
RUN chmod -R ug+rw storage bootstrap/cache 2>/dev/null || true

EXPOSE 8080
CMD ["/bin/sh", "scripts/docker-web.sh"]
