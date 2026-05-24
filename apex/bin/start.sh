#!/bin/bash
set -e

echo "[APEX] Running schema migration..."
php /var/www/html/scripts/migrate.php || echo "[APEX] Migration skipped or failed — continuing"

echo "[APEX] Starting Apache..."
exec apache2-foreground
