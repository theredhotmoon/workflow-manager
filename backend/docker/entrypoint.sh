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

# Migrations run on every start. That fits this stack's single backend container: the schema is
# always the one the code expects, and no deploy step can be forgotten. --force skips Laravel's
# production prompt; already-run migrations are skipped. With several backend replicas, set
# RUN_MIGRATIONS=0 and run them once per deploy instead (README, "Migrations"), so two containers
# never migrate at the same time.
#
# BACKUP_BEFORE_MIGRATE=1: when migrations are pending, pg_dump the database to BACKUP_DIR first
# (default: storage/app/backups, on the backend_storage volume). A failed backup stops the start
# before anything is migrated.
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
    if [ "${BACKUP_BEFORE_MIGRATE:-0}" = "1" ]; then
        if php artisan migrate:status --pending=1 > /dev/null 2>&1; then
            echo "→ No pending migrations: no pre-migration backup needed."
        else
            backup_dir="${BACKUP_DIR:-/var/www/html/storage/app/backups}"
            backup_file="$backup_dir/pre-migrate-$(date -u +%Y%m%dT%H%M%SZ).dump"
            mkdir -p "$backup_dir"
            echo "→ Pending migrations: backing up database '$DB_DATABASE' to $backup_file ..."
            PGPASSWORD="$DB_PASSWORD" pg_dump --format=custom \
                --host="$DB_HOST" --port="${DB_PORT:-5432}" \
                --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
                --file="$backup_file"
            echo "→ Backup written ($(du -h "$backup_file" | cut -f1)). Restore with pg_restore."
        fi
    fi
    echo "→ Running migrations..."
    php artisan migrate --force
else
    echo "→ RUN_MIGRATIONS=0: skipping migrations (run them as a separate deploy step)."
fi

# storage/logs and storage/app are named volumes, so the image's build-time
# chown never reaches files already in them. Anything created there as root
# (by the commands above, or by a `docker compose exec backend php artisan ...`,
# which runs as root) would otherwise lock php-fpm (www-data) out of laravel.log.
# Runs last so it also covers files the bootstrap steps above just created.
echo "→ Fixing storage volume ownership..."
chown -R www-data:www-data storage/logs storage/app

echo "→ Starting services (supervisord)..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
