# syntax=docker/dockerfile:1

# ---- Stage 1: build PHP dependencies (no dev) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev --no-scripts --no-autoloader \
    --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts

# ---- Stage 2: runtime (nginx + php-fpm on Alpine) ----
FROM php:8.4-fpm-alpine AS runtime

# Runtime packages + PHP extensions required by Laravel/JWT/MySQL.
RUN apk add --no-cache nginx supervisor \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS oniguruma-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring bcmath pcntl opcache \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/*

WORKDIR /var/www/html

# Application code with the optimized, production-only vendor tree.
COPY --from=vendor /app /var/www/html

# Container configuration.
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini
COPY docker/php/app.ini /usr/local/etc/php/conf.d/20-app.ini
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
