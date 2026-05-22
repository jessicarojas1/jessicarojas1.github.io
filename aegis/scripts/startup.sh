#!/bin/bash
set -e

echo "[AEGIS] Running database install/migration..."
php /var/www/html/install.php

echo "[AEGIS] Starting Apache..."
exec apache2-foreground
