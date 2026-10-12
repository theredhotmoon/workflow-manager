#!/bin/sh
set -e

echo "──────────────────────────────────────────"
echo "  Shift Changer — backend bootstrap"
echo "──────────────────────────────────────────"

# 1, true or yes (any case) = on; anything else = off.
is_on() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
        1|true|yes) return 0 ;;
        *) return 1 ;;
    esac
}

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
# (default: storage/app/backups, on the backend_storage volume), readable by root only, keeping the
# newest BACKUP_KEEP dumps (default 5). A failed backup stops the start before anything is migrated.
backup_dir="${BACKUP_DIR:-/var/www/html/storage/app/backups}"

if is_on "${RUN_MIGRATIONS:-1}"; then
    if is_on "${BACKUP_BEFORE_MIGRATE:-0}"; then
        if php artisan migrate:status --pending=1 > /dev/null 2>&1; then
            echo "→ Pre-migration backup: on, but no migrations are pending, so none was taken."
        else
            keep="${BACKUP_KEEP:-5}"
            case "$keep" in
                ''|*[!0-9]*|0) echo "→ BACKUP_KEEP='$keep' is not a positive number; keeping 5."; keep=5 ;;
            esac
            backup_file="$backup_dir/pre-migrate-$(date -u +%Y%m%dT%H%M%SZ).dump"
            mkdir -p "$backup_dir"
            chmod 700 "$backup_dir"
            echo "→ Pre-migration backup: migrations are pending; dumping database '$DB_DATABASE' to $backup_file ..."
            # umask 077: the dump holds password hashes, so it is created readable by root only.
            ( umask 077 && PGPASSWORD="$DB_PASSWORD" pg_dump --format=custom \
                --host="$DB_HOST" --port="${DB_PORT:-5432}" \
                --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
                --file="$backup_file" )
            echo "→ Backup written ($(du -h "$backup_file" | cut -f1)). Restore: README, \"Migrations\"."
            # Names sort by time: drop all but the newest $keep.
            ls -1 "$backup_dir"/pre-migrate-*.dump | sort -r | awk -v keep="$keep" 'NR > keep' |
                while read -r old; do
                    rm -f "$old"
                    echo "→ Removed old backup $old (BACKUP_KEEP=$keep)."
                done
        fi
    else
        echo "→ Pre-migration backup: off (BACKUP_BEFORE_MIGRATE='${BACKUP_BEFORE_MIGRATE:-}')."
    fi
    echo "→ Running migrations..."
    php artisan migrate --force
else
    echo "→ Skipping migrations (RUN_MIGRATIONS='$RUN_MIGRATIONS'): run them as a separate deploy step."
fi

# storage/app is a named volume, so the image's build-time chown never reaches files already in
# it; storage/logs is in the container layer. Anything created in either as root (by the commands
# above, or by a `docker compose exec backend php artisan ...`, which runs as root) would otherwise
# lock php-fpm (www-data) out. Runs last so it also covers files the bootstrap steps just created.
# The backups stay root's: php-fpm never needs them.
echo "→ Fixing storage ownership..."
chown -R www-data:www-data storage/logs storage/app
if [ -d "$backup_dir" ]; then
    chown -R root:root "$backup_dir"
fi

echo "→ Starting services (supervisord)..."
exec /usr/bin/supervisord -c /etc/supervisord.conf
