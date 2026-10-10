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
# pdo_pgsql needs libpq's dev headers (libpq-fe.h), and the PHP curl
# extension (Auth::isPasswordBreached()'s HIBP range-API call) needs
# curl's dev headers — neither ships in the base php image. Install both as
# a virtual, named package group so they can be cleanly removed after
# compiling; keep the actual runtime shared libraries (libpq, curl)
# installed separately so the compiled extensions still load at runtime.
RUN apk add --no-cache --virtual .build-deps postgresql-dev curl-dev \
    && docker-php-ext-install pdo pdo_pgsql curl \
    && apk add --no-cache libpq curl \
    && apk del .build-deps

# PHP's own default upload_max_filesize (2M) is BELOW CsvImport::MAX_FILE_BYTES
# (5MB) — a file between those two sizes would be rejected by PHP itself
# before CsvImport.php's own size check ever runs, silently out of sync with
# what the app documents/enforces. Raise it here, with headroom for
# post_max_size (which also bounds the rest of the multipart form: CSRF
# token, connector_id) above the file size itself.
RUN { \
    echo 'upload_max_filesize = 8M'; \
    echo 'post_max_size = 10M'; \
    } > /usr/local/etc/php/conf.d/verity-uploads.ini

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
