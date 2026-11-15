#!/bin/sh
set -e

echo "==> [TruckOps] Initializing backend container for Render..."

# 1. Resolve Port for Render (Render sets $PORT dynamically; default to 10000)
export PORT="${PORT:-10000}"
echo "==> [TruckOps] Binding Nginx to dynamic port: :${PORT}"

# 2. Template Nginx configuration with the dynamic $PORT
envsubst '${PORT}' < /etc/nginx/conf.d/default.conf.template > /etc/nginx/conf.d/default.conf

# 3. Create required runtime storage and cache directories
mkdir -p /var/www/html/storage/framework/cache/data \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/framework/views \
         /var/www/html/storage/logs \
         /var/www/html/bootstrap/cache

# 4. Enforce strict permissions for www-data and web server
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# 5. Create storage symlink if missing
if [ ! -L /var/www/html/public/storage ]; then
    echo "==> [TruckOps] Creating public storage symlink..."
    php artisan storage:link --no-interaction || true
fi

# 6. Production optimization caching
if [ "${APP_ENV}" = "production" ]; then
    echo "==> [TruckOps] Running production caches (config, routes, views)..."
    php artisan config:cache --no-interaction || true
    php artisan route:cache --no-interaction || true
    php artisan view:cache --no-interaction || true
else
    echo "==> [TruckOps] Running in non-production mode (${APP_ENV}); clearing stale caches..."
    php artisan config:clear --no-interaction || true
    php artisan route:clear --no-interaction || true
    php artisan view:clear --no-interaction || true
fi

# 7. Automated database migrations
if [ "${RUN_MIGRATIONS}" = "true" ] || [ "${AUTO_MIGRATE}" = "true" ]; then
    echo "==> [TruckOps] Running database migrations..."
    php artisan migrate --force --no-interaction
fi

# 8. Optional database seeding (e.g. for initial deployment)
if [ "${SEED_DATABASE}" = "true" ]; then
    echo "==> [TruckOps] Seeding initial database records..."
    php artisan db:seed --force --no-interaction
fi

echo "==> [TruckOps] Starting Supervisord (PHP-FPM & Nginx)..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
