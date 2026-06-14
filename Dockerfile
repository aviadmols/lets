# FrankPHP (PHP 8.4) image for the web service. Worker/scheduler services reuse
# this same image and override the start command via the Procfile.
FROM dunglas/frankenphp:1-php8.4

# System packages required by the PHP extensions below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libicu-dev libzip-dev libpq-dev libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions. pdo_pgsql (Postgres), redis (queue/cache/Horizon), the rest
# are Laravel/Filament essentials. opcache for production throughput.
RUN install-php-extensions \
        intl zip pdo_pgsql gd bcmath pcntl sockets opcache redis

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
