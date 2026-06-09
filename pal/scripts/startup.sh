#!/bin/bash
set -e

# Honor a platform-provided $PORT (Azure App Service, some PaaS) for Apache.
if [ -n "${PORT}" ] && [ "${PORT}" != "80" ]; then
  sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf || true
  sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf || true
fi

echo "[PAL] Running database install/migration..."
php /var/www/html/install.php || echo "[PAL] Install skipped or DB not ready — continuing"

echo "[PAL] Starting Apache..."
exec apache2-foreground
