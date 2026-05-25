FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql gd opcache

# php.ini hardening
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "expose_php = Off" >> "$PHP_INI_DIR/php.ini" \
    && echo "display_errors = Off" >> "$PHP_INI_DIR/php.ini" \
    && echo "log_errors = On" >> "$PHP_INI_DIR/php.ini" \
    && echo "opcache.enable=1\nopcache.memory_consumption=128\nopcache.validate_timestamps=0" \
       >> "$PHP_INI_DIR/conf.d/opcache.ini"

WORKDIR /var/www/aegis
COPY . .
RUN mkdir -p uploads/evidence uploads/documents logs \
    && chmod 750 uploads uploads/evidence uploads/documents \
    && chown -R www-data:www-data uploads logs

EXPOSE 9000
CMD ["php-fpm"]
