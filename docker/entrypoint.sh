#!/bin/sh
set -e

cd /var/www/html

# Recreate runtime dirs (a mounted volume can shadow the ones baked into the
# image) and make them writable.
mkdir -p \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Public symlink for files stored on the "local" disk (e.g. uploaded images).
php artisan storage:link --force >/dev/null 2>&1 || true

# Opt-in DB migration on container start (set RUN_MIGRATIONS=true).
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

# Cache config/views. NOTE: route:cache is intentionally skipped because
# routes/web.php defines a closure route, which cannot be serialized.
php artisan config:cache >/dev/null 2>&1 || true
php artisan view:cache   >/dev/null 2>&1 || true

exec "$@"
