FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    poppler-utils \
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

# Allow .htaccess overrides in the document root
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . .
RUN mkdir -p uploads/evidence uploads/documents logs \
    && chmod 750 uploads uploads/evidence uploads/documents \
    && chown -R www-data:www-data uploads logs

COPY scripts/startup.sh /startup.sh
RUN chmod +x /startup.sh

EXPOSE 80
CMD ["/startup.sh"]
