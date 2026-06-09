#!/bin/bash
set -e

# Bind Apache to the platform-provided port ($PORT on Render / Azure App Service;
# defaults to 80). Apache config does NOT expand shell variables, so we write the
# resolved value at runtime. Overwriting ports.conf is safe: TLS is terminated by
# the platform's reverse proxy, so the in-container server is plain HTTP.
PORT="${PORT:-80}"
echo "Listen ${PORT}" > /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf || true

echo "[PALADIN] Running database install/migration..."
php /var/www/html/install.php || echo "[PALADIN] Install skipped or DB not ready — continuing"

echo "[PALADIN] Starting Apache on port ${PORT}..."
exec apache2-foreground
