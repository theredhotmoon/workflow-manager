#!/bin/sh
set -e

echo "──────────────────────────────────────────"
echo "  Shift Changer — backend bootstrap"
echo "──────────────────────────────────────────"

# Clear & rebuild caches using the runtime env vars injected by Docker
echo "→ Caching config / routes / views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Run pending migrations automatically on every start.
# Safe in production because --force skips the interactive confirmation
# and Laravel migrations are idempotent.
echo "→ Running migrations..."
php artisan migrate --force

# storage/logs and storage/app are named volumes, so the image's build-time
# chown never reaches files already in them. Anything created there as root
# (by the commands above, or by a `docker compose exec backend php artisan ...`,
# which runs as root) would otherwise lock php-fpm (www-data) out of laravel.log.
# Runs last so it also covers files the bootstrap steps above just created.
echo "→ Fixing storage volume ownership..."
chown -R www-data:www-data storage/logs storage/app

echo "→ Starting services (supervisord)..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
