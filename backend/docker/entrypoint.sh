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

echo "→ Starting services (supervisord)..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
