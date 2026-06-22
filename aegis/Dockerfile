FROM php:8.3-apache@sha256:954d6198d9877b396382aa8a93d8be4832ab4908a7dc64f58dcc4be2833b8e29  # php:8.3-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    poppler-utils \
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql gd opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# php.ini hardening
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo 'expose_php = Off'               >> "$PHP_INI_DIR/php.ini" \
    && echo 'display_errors = Off'           >> "$PHP_INI_DIR/php.ini" \
    && echo 'log_errors = On'                >> "$PHP_INI_DIR/php.ini" \
    && echo 'opcache.enable=1'               >> "$PHP_INI_DIR/conf.d/opcache.ini" \
    && echo 'opcache.memory_consumption=128' >> "$PHP_INI_DIR/conf.d/opcache.ini" \
    && echo 'opcache.validate_timestamps=0'  >> "$PHP_INI_DIR/conf.d/opcache.ini"

# Allow .htaccess overrides; listen on an unprivileged port (8080) and route
# logs/PidFile/locks to stdout/stderr/tmp so the whole process tree runs as the
# non-root www-data user (no root PID 1). Aligns with docker/Dockerfile.hardened.
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf \
    && sed -i 's|Listen 80$|Listen 8080|' /etc/apache2/ports.conf \
    && sed -i 's|:80>|:8080>|' /etc/apache2/sites-available/000-default.conf \
    && printf '%s\n' \
        'ServerName localhost' \
        'PidFile /tmp/apache2.pid' \
        'Mutex file:/tmp' \
        'ErrorLog /dev/stderr' \
        'TransferLog /dev/stdout' \
        > /etc/apache2/conf-available/aegis-nonroot.conf \
    && a2enconf aegis-nonroot

WORKDIR /var/www/html
COPY . .
RUN mkdir -p uploads/evidence uploads/documents logs \
    && chmod 750 uploads uploads/evidence uploads/documents \
    && chown -R www-data:www-data /var/www/html /var/log/apache2 /var/run/apache2

COPY scripts/startup.sh /startup.sh
RUN chmod +x /startup.sh

# Non-root: Apache listens on 8080 and runs entirely as www-data (set as USER
# below). nginx (see docker-compose.yml) proxies to app:8080 and is the public
# surface. Satisfies runAsNonRoot + no-new-privileges; pairs with the readonly
# root FS pattern in docker/Dockerfile.hardened.
EXPOSE 8080
USER www-data

# Liveness probe — hits the unauthenticated /healthz endpoint (see HealthController).
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://localhost:8080/healthz || exit 1

CMD ["/startup.sh"]
