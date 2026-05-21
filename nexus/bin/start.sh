#!/bin/bash
set -e

echo "[NEXUS] Running schema migration..."
php /var/www/html/scripts/migrate.php || echo "[NEXUS] Migration skipped or failed — continuing"

echo "[NEXUS] Starting Apache..."
exec apache2-foreground
