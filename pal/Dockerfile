# ── PAL Platform — production image ─────────────────────────────────────────
# Cloud-agnostic; runs on Azure Government (Container Apps / AKS / App Service),
# AWS GovCloud (ECS / EKS / EC2), Kubernetes, Docker Swarm or on-prem Linux.
FROM php:8.3-apache

# System deps + PHP extensions (PostgreSQL, GD, OPcache, sodium for at-rest encryption)
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl libpq-dev libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libsodium-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql gd opcache zip sodium \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# php.ini hardening + OPcache
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'expose_php = Off'; \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'upload_max_filesize = 32M'; \
        echo 'post_max_size = 40M'; \
        echo 'session.cookie_httponly = 1'; \
        echo 'session.cookie_samesite = Strict'; \
    } >> "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
    } >> "$PHP_INI_DIR/conf.d/opcache.ini"

# Allow .htaccess overrides in the document root
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf \
    && sed -i 's|Listen 80|Listen ${PORT:-80}|' /etc/apache2/ports.conf 2>/dev/null || true

WORKDIR /var/www/html
COPY . .

RUN mkdir -p uploads/documents uploads/attachments uploads/evidence logs \
    && chown -R www-data:www-data uploads logs \
    && chmod -R 750 uploads logs

COPY scripts/startup.sh /startup.sh
RUN chmod +x /startup.sh

# Container health endpoint for orchestrators (K8s liveness/readiness, ECS, ACA)
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://localhost:${PORT:-80}/health || exit 1

EXPOSE 80
CMD ["/startup.sh"]
