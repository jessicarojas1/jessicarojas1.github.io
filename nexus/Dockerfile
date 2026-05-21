FROM php:8.2-apache

RUN apt-get update -qq && \
    apt-get install -y --no-install-recommends libpq-dev && \
    docker-php-ext-install pdo pdo_pgsql && \
    a2enmod rewrite && \
    rm -rf /var/lib/apt/lists/*

# Use public/ as the document root
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/html/public\n\
    <Directory /var/www/html/public>\n\
        Options -Indexes\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html

# Mirror the top-level /app static assets under public/ so Apache serves them
# directly at /app/nexus.js and /app/nexus.css (no PHP needed).
RUN mkdir -p /var/www/html/public/app \
 && cp -f /var/www/html/app/nexus.js  /var/www/html/public/app/nexus.js \
 && cp -f /var/www/html/app/nexus.css /var/www/html/public/app/nexus.css

RUN chown -R www-data:www-data /var/www/html

COPY bin/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

EXPOSE 80
CMD ["start.sh"]
