# syntax=docker/dockerfile:1

FROM node:22-alpine AS assets

WORKDIR /app

COPY GestioneAssenze/package.json GestioneAssenze/package-lock.json ./
RUN npm ci

COPY GestioneAssenze/resources ./resources
COPY GestioneAssenze/public ./public
COPY GestioneAssenze/vite.config.js GestioneAssenze/postcss.config.js GestioneAssenze/tailwind.config.js GestioneAssenze/jsconfig.json ./
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

COPY GestioneAssenze/composer.json GestioneAssenze/composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader \
        --prefer-dist

COPY --chown=www-data:www-data GestioneAssenze/artisan ./artisan
COPY --chown=www-data:www-data GestioneAssenze/app ./app
COPY --chown=www-data:www-data GestioneAssenze/bootstrap ./bootstrap
COPY --chown=www-data:www-data GestioneAssenze/config ./config
COPY --chown=www-data:www-data GestioneAssenze/database ./database
COPY --chown=www-data:www-data GestioneAssenze/lang ./lang
COPY --chown=www-data:www-data GestioneAssenze/public ./public
COPY --chown=www-data:www-data GestioneAssenze/resources ./resources
COPY --chown=www-data:www-data GestioneAssenze/routes ./routes
COPY --chown=www-data:www-data GestioneAssenze/scripts ./scripts
COPY --chown=www-data:www-data GestioneAssenze/storage ./storage
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

COPY Deploy/docker/php/php.ini /usr/local/etc/php/conf.d/99-gestione-assenze.ini
COPY Deploy/docker/php/entrypoint.sh /usr/local/bin/docker-entrypoint
COPY Deploy/docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY Deploy/docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN chmod +x /usr/local/bin/docker-entrypoint

EXPOSE 80

ENTRYPOINT ["docker-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
