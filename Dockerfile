# FrankPHP (PHP 8.4) image for the web service. Worker/scheduler services reuse
# this same image and override the start command via the Procfile.
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

# Composer from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps first (better layer caching).
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader || true

# App source.
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader || true

# Storage + cache must be writable.
RUN chmod -R ug+rw storage bootstrap/cache 2>/dev/null || true

EXPOSE 8080
CMD ["/bin/sh", "scripts/docker-web.sh"]
