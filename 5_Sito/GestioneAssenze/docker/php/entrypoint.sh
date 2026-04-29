#!/bin/sh
set -e

APP_KEY_FILE="${APP_KEY_FILE:-storage/framework/docker-app.key}"

mkdir -p \
    bootstrap/cache \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    /run/nginx

chown -R www-data:www-data bootstrap/cache storage /run/nginx

ensure_app_key() {
    if [ -n "${APP_KEY:-}" ]; then
        return 0
    fi

    if [ -f "${APP_KEY_FILE}" ]; then
        APP_KEY="$(cat "${APP_KEY_FILE}")"
        export APP_KEY
        return 0
    fi

    APP_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
    export APP_KEY

    printf '%s' "${APP_KEY}" > "${APP_KEY_FILE}"
    chown www-data:www-data "${APP_KEY_FILE}"
    chmod 600 "${APP_KEY_FILE}"

    echo "APP_KEY mancante: generata automaticamente e salvata in ${APP_KEY_FILE}." >&2
}

if [ "${1:-}" = "supervisord" ]; then
    ensure_app_key

    if [ "${CACHE_LARAVEL:-true}" = "true" ]; then
        su-exec www-data php artisan optimize:clear
    fi

    if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
        su-exec www-data php artisan migrate --force --seed
    fi

    if [ "${CACHE_LARAVEL:-true}" = "true" ]; then
        su-exec www-data php artisan config:cache
        su-exec www-data php artisan route:cache
        su-exec www-data php artisan view:cache
    fi

    exec "$@"
fi

if [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ]; then
    ensure_app_key
    exec su-exec www-data "$@"
fi

exec "$@"
