#!/bin/sh
set -e

mkdir -p \
    bootstrap/cache \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    /run/nginx

chown -R www-data:www-data bootstrap/cache storage /run/nginx

if [ "${1:-}" = "supervisord" ]; then
    if [ -z "${APP_KEY:-}" ]; then
        echo "APP_KEY mancante. Generala e inseriscila in .env.docker prima di avviare il deploy." >&2
        exit 1
    fi

    if [ "${CACHE_LARAVEL:-true}" = "true" ]; then
        su-exec www-data php artisan optimize:clear
    fi

    if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
        su-exec www-data php artisan migrate --force
    fi

    if [ "${CACHE_LARAVEL:-true}" = "true" ]; then
        su-exec www-data php artisan config:cache
        su-exec www-data php artisan route:cache
        su-exec www-data php artisan view:cache
    fi

    exec "$@"
fi

if [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ]; then
    exec su-exec www-data "$@"
fi

exec "$@"
