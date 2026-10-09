# syntax=docker/dockerfile:1
# VERITY — Enterprise Identity & Access Governance
#
# Two-stage build: a "base" stage with the PHP runtime and the pdo_pgsql
# extension compiled in, and a final "app" stage that copies only the
# application source, drops to a non-root user, and exposes a health check.
# The app has zero Composer dependencies today (see composer.json) — the
# bootstrap's built-in PSR-4 autoloader runs with no `vendor/` directory —
# so there is intentionally no separate "composer install" stage; add one
# here if/when a real dependency is introduced.

FROM php:8.3-cli-alpine AS base
RUN docker-php-ext-install pdo pdo_pgsql \
    && apk add --no-cache curl

FROM base AS app
WORKDIR /var/www/verity

# Non-root runtime user.
RUN addgroup -S verity && adduser -S verity -G verity

COPY --chown=verity:verity . .

# Never bake secrets or a real .env into the image — only the placeholder
# example file ships; real configuration comes from the environment.
RUN rm -f .env

ENV APP_ENV=production \
    PORT=8080
EXPOSE 8080

USER verity

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${PORT}/health" || exit 1

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t public"]
