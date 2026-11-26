#!/usr/bin/env bash
# Creates the Laravel 13 app in apps/api without touching modules/ or README.md.
set -euo pipefail
cd "$(dirname "$0")/.."
[ -f apps/api/composer.json ] && { echo "apps/api already has a Laravel app"; exit 0; }

tmp="$(mktemp -d)"
composer create-project laravel/laravel "$tmp/api" "^13.0" --no-interaction
cp -rn "$tmp/api/." apps/api/
rm -rf "$tmp"

cd apps/api
composer require laravel/octane --no-interaction
composer require --dev pestphp/pest pestphp/pest-plugin-laravel larastan/larastan laravel/pint --with-all-dependencies --no-interaction
php artisan octane:install --server=frankenphp --no-interaction
echo 'Done. In composer.json add to autoload.psr-4:  "Modules\\": "modules/"  then run composer dump-autoload.'
