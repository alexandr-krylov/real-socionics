#!/bin/sh
set -e

# Назначаем права на storage и cache
echo "Fixing permissions..."
chown -R www-data:www-data /var/www/app/backend/storage /var/www/app/backend/bootstrap/cache || true
chmod -R 775 /var/www/app/backend/storage /var/www/app/backend/bootstrap/cache || true

echo "Starting PHP-FPM..."
exec php-fpm
