# ─── Stage 1: Composer dependencies ──────────────────────────────────────────
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


# ─── Stage 2: PostgreSQL client tools of the server's major version ──────────
# pg_dump/pg_restore for the optional pre-migration backup (BACKUP_BEFORE_MIGRATE, entrypoint.sh).
# They must match the db server's major version (docker-compose.yml: postgres:15-alpine): a dump
# written by a newer pg_dump can't be read by the server's pg_restore. Alpine's own packages only go
# back to 16, so they come from the same image the db runs (same Alpine release as php:8.4-fpm-alpine).
# Keep this tag in step with the db image.
FROM postgres:15-alpine AS pgclient


# ─── Stage 3: Runtime image ───────────────────────────────────────────────────
FROM php:8.4-fpm-alpine

LABEL maintainer="Shift Changer"

# System dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    postgresql-dev \
    # Libraries the PostgreSQL 15 client tools below link against (Kerberos/LDAP via their libpq)
    krb5-libs \
    libldap \
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

# Copy app + pre-built vendor from stage 1
COPY --from=vendor /app /var/www/html

# PostgreSQL 15 client tools in their own directory, with their own libpq: put on the default library
# path, libpq 15 would replace the libpq that pdo_pgsql was built against. /usr/local/bin wrappers
# point the tools at it.
COPY --from=pgclient /usr/local/bin/pg_dump /usr/local/bin/pg_restore /opt/pg15/bin/
COPY --from=pgclient /usr/local/lib/libpq.so.5* /opt/pg15/lib/
COPY docker/pg15-tool.sh /usr/local/bin/pg_dump
COPY docker/pg15-tool.sh /usr/local/bin/pg_restore
RUN chmod +x /usr/local/bin/pg_dump /usr/local/bin/pg_restore \
    && pg_dump --version | grep -q ' 15\.' \
    && pg_restore --version | grep -q ' 15\.'

# Docker-specific configs
COPY docker/nginx.conf      /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini          /usr/local/etc/php/conf.d/zzz-custom.ini
COPY docker/entrypoint.sh    /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    # One path each: this /bin/sh has no brace expansion. cache/data must exist, or Laravel 13's
    # cache:clear (file store) exits 1 on a fresh container (the e2e global setup runs it).
    && mkdir -p /var/www/html/storage/framework/cache/data \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/views \
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
