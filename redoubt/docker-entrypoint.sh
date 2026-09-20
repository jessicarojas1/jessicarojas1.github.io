#!/bin/sh
# Bind Apache to the platform-provided $PORT (Render/K8s/Docker), default 8080.
set -e
PORT="${PORT:-8080}"
sed -ri "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s#<VirtualHost \\*:[0-9]+>#<VirtualHost *:${PORT}>#" /etc/apache2/sites-available/*.conf
exec "$@"
