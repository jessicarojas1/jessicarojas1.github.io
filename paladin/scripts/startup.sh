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
# The managed Postgres can be briefly unreachable at boot (e.g. a free-tier DB
# waking up). install.php exits non-zero when it cannot connect, so retry with a
# short backoff rather than starting Apache against a stale/unmigrated schema —
# otherwise pending migrations (new columns/tables) never apply and requests 500.
INSTALL_ATTEMPTS="${INSTALL_ATTEMPTS:-12}"
INSTALL_DELAY="${INSTALL_DELAY:-5}"
attempt=1
until php /var/www/html/install.php; do
    if [ "${attempt}" -ge "${INSTALL_ATTEMPTS}" ]; then
        echo "[PALADIN] Install/migration still failing after ${INSTALL_ATTEMPTS} attempts — starting Apache anyway"
        break
    fi
    echo "[PALADIN] Install/migration failed (attempt ${attempt}/${INSTALL_ATTEMPTS}); DB may be waking — retrying in ${INSTALL_DELAY}s"
    attempt=$((attempt + 1))
    sleep "${INSTALL_DELAY}"
done

echo "[PALADIN] Starting Apache on port ${PORT}..."
exec apache2-foreground
