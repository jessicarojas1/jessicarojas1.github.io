# REDOUBT — GMRE Program Portal Framework
# Discovery-phase container: serves the Product & Architecture Discovery Package.
# Multi-stage: composer (autoloader) -> php:8.2-apache runtime, $PORT-aware, healthcheck.

# ---- Stage 1: dependencies (autoloader only; no runtime deps yet) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts || true

# ---- Stage 2: runtime ----
FROM php:8.2-apache AS runtime

# Serve from /public and enable rewrite so the front controller handles routing.
# curl for the HEALTHCHECK; libpq for the PostgreSQL PDO driver (Phase 1+).
RUN apt-get update && apt-get install -y --no-install-recommends curl libpq-dev \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && a2enmod rewrite headers

WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html
COPY --from=vendor /app/vendor /var/www/html/vendor

# Route all non-file requests through the front controller.
RUN printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n\tAllowOverride All\n\tRequire all granted\n\tFallbackResource /index.php\n</Directory>\n' \
      > /etc/apache2/conf-available/redoubt.conf \
 && a2enconf redoubt

# The platform (Render / K8s / Docker) provides $PORT at runtime; the entrypoint
# rewrites Apache's listen port to it before start (default 8080). Apache's master
# runs as root and drops worker processes to www-data (the php:apache default).
ENV PORT=8080
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=4s --start-period=10s --retries=3 \
  CMD curl -fsS "http://127.0.0.1:${PORT:-8080}/health" || exit 1

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
