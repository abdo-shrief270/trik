# syntax=docker/dockerfile:1.7
# Trik API: Laravel 13 on Octane + FrankenPHP (PHP 8.5).
# Build from apps/api:  docker build -t trik-api .

# One PHP for build and runtime: the composer image ships an older PHP than the app requires, so packages are
# installed on the same base the app runs on (with its extensions, so platform checks are real).
FROM dunglas/frankenphp:1-php8.5 AS base
RUN install-php-extensions pdo_pgsql redis intl bcmath pcntl opcache zip

FROM base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

FROM base AS runtime
ENV APP_ENV=production \
    APP_DEBUG=false \
    OCTANE_SERVER=frankenphp \
    LOG_CHANNEL=stderr
WORKDIR /app
COPY --from=vendor /app /app
# Octane copies its FrankenPHP worker into public/ on start, which www-data can't write: install it now.
# FrankenPHP's embedded Caddy keeps its state in /data and /config, which must be writable too.
RUN php artisan package:discover --ansi \
 && cp vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php public/frankenphp-worker.php \
 && chown -R www-data:www-data storage bootstrap/cache /data/caddy /config/caddy
USER www-data
EXPOSE 8080
# the image has no curl: use PHP itself for the health probe
HEALTHCHECK --interval=15s --timeout=3s --retries=3 CMD php -r "exit(@file_get_contents('http://127.0.0.1:8080/healthz') === false ? 1 : 0);"
# Config and routes are cached at start so they pick up the runtime environment.
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan event:cache && exec php artisan octane:frankenphp --host=0.0.0.0 --port=8080 --workers=auto --max-requests=1000"]
