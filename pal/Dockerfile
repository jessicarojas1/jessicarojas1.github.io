# syntax=docker/dockerfile:1
# PAL (Enterprise Process Asset Library): one image, several roles.
#
#   docker run pal web        nginx + PHP-FPM on :8080 (SPA, BFF API, health)   [default]
#   docker run pal worker     Messenger consumer: durable queue + scheduler
#   docker run pal migrate    apply database migrations, then exit
#   docker run pal console …  any bin/console command
#
# Build context: this directory (pal/).
# Base images are pinned by version tag; pin by digest in your registry mirror
# (see OPEN_ITEMS.md). Runs as an unprivileged user; no process needs root.

ARG PHP_IMAGE=php:8.4-fpm-alpine
ARG NODE_IMAGE=node:24-alpine
ARG COMPOSER_IMAGE=composer:2

# --- Frontend build -----------------------------------------------------------
FROM ${NODE_IMAGE} AS frontend
WORKDIR /build
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN npm run build

# --- Composer binary ------------------------------------------------------------
FROM ${COMPOSER_IMAGE} AS composer

# --- PHP runtime base ----------------------------------------------------------
FROM ${PHP_IMAGE} AS runtime
# Apply OS security updates published after the base image was built (Trivy gates HIGH/CRITICAL).
# hadolint ignore=DL3017
RUN apk upgrade --no-cache
RUN apk add --no-cache --virtual .build-deps postgresql-dev icu-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl opcache \
    && apk add --no-cache libpq icu-libs nginx supervisor tini \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/*
COPY deploy/php/php.ini /usr/local/etc/php/conf.d/zz-pal.ini
COPY deploy/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-pal.conf
COPY deploy/nginx/nginx.conf /etc/nginx/nginx.conf
COPY deploy/supervisord.conf /etc/supervisord.conf
RUN addgroup -S -g 10001 pal && adduser -S -D -H -u 10001 -G pal pal \
    # Remove the image's default [www] pool (www.conf, docker.conf, zz-docker.conf); zz-pal.conf replaces it.
    && rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf \
    && mkdir -p /var/lib/nginx /var/log/nginx /run/nginx \
    && chown -R pal:pal /var/lib/nginx /var/log/nginx /run/nginx

# --- Backend dependencies (production only) -------------------------------------
FROM runtime AS backend
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /srv/pal/backend
COPY backend/composer.json backend/composer.lock backend/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress --no-autoloader
COPY backend/ ./
# Symfony needs a .env file to exist; it holds no secrets (the template). Real values
# come from environment variables at runtime, which always take precedence.
RUN cp .env.example .env \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && APP_ENV=prod APP_SECRET=build-only-placeholder php bin/console cache:warmup --env=prod \
    && rm -rf var/log/* tests phpunit.dist.xml phpstan*.neon deptrac.yaml

# --- Final image -------------------------------------------------------------------
FROM runtime AS app
LABEL org.opencontainers.image.title="PAL" \
      org.opencontainers.image.description="Enterprise Process Asset Library (document control)"
WORKDIR /srv/pal
COPY --from=backend --chown=pal:pal /srv/pal/backend /srv/pal/backend
COPY --from=frontend --chown=root:root /build/dist /srv/pal/frontend
COPY --chmod=0755 deploy/docker-entrypoint.sh /usr/local/bin/pal-entrypoint
# Writable runtime paths live under /tmp and var/; the application code is read-only to the app user.
RUN chown -R root:root /srv/pal/backend \
    && mkdir -p /srv/pal/backend/var/cache /srv/pal/backend/var/log \
    && chown -R pal:pal /srv/pal/backend/var

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    TRUSTED_PROXIES=private_ranges \
    PAL_SESSION_COOKIE_NAME=__Host-pal_session \
    PAL_SESSION_COOKIE_SECURE=1

USER pal
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -qO- http://127.0.0.1:8080/health/live >/dev/null || exit 1
ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/pal-entrypoint"]
CMD ["web"]
