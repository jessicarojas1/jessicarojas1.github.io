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
# directly at /app/apex.js and /app/apex.css (no PHP needed).
RUN mkdir -p /var/www/html/public/app \
 && cp -f /var/www/html/app/apex.js  /var/www/html/public/app/apex.js \
 && cp -f /var/www/html/app/apex.css /var/www/html/public/app/apex.css

RUN chown -R www-data:www-data /var/www/html

COPY bin/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Privilege model: PID 1 stays root only so Apache can bind privileged port :80;
# apache2-foreground then drops its worker processes to the unprivileged
# www-data user (set as the document-root owner above), so request-handling code
# never runs as root. A non-root PID 1 is not used here because it cannot bind
# :80 on this Render-style port-80 deployment; for orchestrated/IL4+ deploys that
# need runAsNonRoot, listen on an unprivileged port (e.g. 8080) and add a `USER
# www-data` directive — see aegis/docker/Dockerfile.hardened for the pattern.
EXPOSE 80
CMD ["start.sh"]
