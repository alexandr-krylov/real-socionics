#!/bin/sh
set -e

# Путь где ожидаем смонтированный сертификат (в docker-compose монтируем fullchain.pem сюда)
CERT_SRC="/etc/ssl/certs/mailcert.pem"
# Как он будет называться в системном хранилище
CERT_DEST="/usr/local/share/ca-certificates/mailcert.crt"

# Подождать появления файла (таймаут 60s)
WAIT=60
COUNT=0
while [ ! -f "$CERT_SRC" ] && [ $COUNT -lt $WAIT ]; do
  echo "Waiting for $CERT_SRC ... ($COUNT/$WAIT)"
  sleep 1
  COUNT=$((COUNT+1))
done

if [ -f "$CERT_SRC" ]; then
  echo "Found $CERT_SRC — installing to system CA store"
  # Копируем как crt в /usr/local/share/ca-certificates
  cp "$CERT_SRC" "$CERT_DEST"
  # Обновляем доверенные корни
  update-ca-certificates || true
else
  echo "Certificate $CERT_SRC not found after $WAIT seconds, continuing without installing CA"
fi
# Назначаем права на storage и cache
echo "Fixing permissions..."
chown -R www-data:www-data /var/www/app/backend/storage /var/www/app/backend/bootstrap/cache || true
chmod -R 775 /var/www/app/backend/storage /var/www/app/backend/bootstrap/cache || true

echo "Starting PHP-FPM..."
exec php-fpm
