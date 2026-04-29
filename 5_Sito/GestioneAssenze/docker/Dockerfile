# syntax=docker/dockerfile:1

FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js postcss.config.js tailwind.config.js jsconfig.json ./
RUN npm run build


FROM php:8.3-fpm-alpine AS app

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PATH="/opt/venv/bin:${PATH}" \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

RUN apk add --no-cache \
        bash \
        curl \
        icu-dev \
        libzip-dev \
        nginx \
        oniguruma-dev \
        py3-pip \
        python3 \
        sqlite-dev \
        su-exec \
        supervisor \
    && apk add --no-cache --virtual .build-deps ${PHPIZE_DEPS} \
    && docker-php-ext-install \
        bcmath \
        intl \
        mbstring \
        pcntl \
        pdo_mysql \
        pdo_sqlite \
        zip \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader \
        --prefer-dist

COPY --chown=www-data:www-data artisan ./artisan
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data database ./database
COPY --chown=www-data:www-data lang ./lang
COPY --chown=www-data:www-data public ./public
COPY --chown=www-data:www-data resources ./resources
COPY --chown=www-data:www-data routes ./routes
COPY --chown=www-data:www-data scripts ./scripts
COPY --chown=www-data:www-data storage ./storage
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize \
    && python3 -m venv /opt/venv \
    && /opt/venv/bin/pip install --no-cache-dir -r scripts/requirements.txt \
    && mkdir -p \
        bootstrap/cache \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        /run/nginx \
    && chown -R www-data:www-data bootstrap/cache storage public/build /run/nginx

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-gestione-assenze.ini
COPY docker/php/entrypoint.sh /usr/local/bin/docker-entrypoint
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN chmod +x /usr/local/bin/docker-entrypoint

EXPOSE 80

ENTRYPOINT ["docker-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
