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

# redis is a REMOTE (PECL) extension. pecl.php.net intermittently returns
# "504 Gateway Timeout", which failed the WHOLE build (and every deploy) whenever
# PECL was flaky — the app then froze on the last good build. Retry with backoff so
# a transient gateway timeout can't break the deploy, and fail loudly only if redis
# genuinely can't be installed after all attempts.
RUN for i in 1 2 3 4 5 6 7 8; do \
        install-php-extensions redis && break; \
        echo ">> redis (PECL) install attempt $i failed — retrying in 15s"; \
        sleep 15; \
    done; \
    php -m | grep -qi '^redis$' || { echo "FATAL: redis extension missing after retries"; exit 1; }

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
