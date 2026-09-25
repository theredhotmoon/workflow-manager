# ─── Stage 1: Composer dependencies (production) ─────────────────────────────
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative


# ─── Stage 1b: Composer dependencies (with dev) ───────────────────────────────
FROM composer:2 AS vendor-dev

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize


# ─── Stage 2: Runtime image ───────────────────────────────────────────────────
FROM php:8.2-fpm-alpine

LABEL maintainer="Shift Changer"

# System dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    curl \
    shadow

# PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    zip \
    opcache \
    pcntl

WORKDIR /var/www/html

# Copy app + pre-built vendor from stage 1b (includes dev dependencies for testing)
COPY --from=vendor-dev /app /var/www/html

# Docker-specific configs
COPY docker/nginx.conf      /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini          /usr/local/etc/php/conf.d/zzz-custom.ini
COPY docker/entrypoint.sh    /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    && mkdir -p /var/www/html/storage/framework/{cache,sessions,views} \
    && mkdir -p /var/www/html/storage/logs \
    && mkdir -p /var/www/html/bootstrap/cache \
    # nginx needs to write its pid and log
    && mkdir -p /run/nginx \
    && chown -R www-data:www-data \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache \
    && chmod -R 775 \
        /var/www/html/storage \
        /var/www/html/bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
