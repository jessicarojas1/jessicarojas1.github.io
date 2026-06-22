FROM php:8.2-apache@sha256:034cb2b91d74209744da4eec2696075ed97f52788668ffa68967fdd6ee6a306f  # php:8.2-apache

RUN apt-get update -qq && \
    apt-get install -y --no-install-recommends libpq-dev && \
    docker-php-ext-install pdo pdo_pgsql && \
    a2enmod rewrite && \
    rm -rf /var/lib/apt/lists/*

# Listen on an unprivileged port (8080) so Apache can run as a non-root user
# without CAP_NET_BIND_SERVICE. Use public/ as the document root and send logs
# to stdout/stderr so the rootfs can stay read-only.
RUN sed -i 's|Listen 80$|Listen 8080|' /etc/apache2/ports.conf \
 && echo '<VirtualHost *:8080>\n\
    DocumentRoot /var/www/html/public\n\
    <Directory /var/www/html/public>\n\
        Options -Indexes\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog /dev/stderr\n\
    CustomLog /dev/stdout combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf \
 && printf '%s\n' 'ServerName localhost' 'PidFile /tmp/apache2.pid' 'Mutex file:/tmp' \
        > /etc/apache2/conf-available/apex-nonroot.conf \
 && a2enconf apex-nonroot

COPY . /var/www/html

# Mirror the top-level /app static assets under public/ so Apache serves them
# directly at /app/apex.js and /app/apex.css (no PHP needed).
RUN mkdir -p /var/www/html/public/app \
 && cp -f /var/www/html/app/apex.js  /var/www/html/public/app/apex.js \
 && cp -f /var/www/html/app/apex.css /var/www/html/public/app/apex.css

# Make the Apache runtime dirs writable by the non-root www-data user so the
# whole PID-1 process tree can run unprivileged (no root at all).
RUN chown -R www-data:www-data /var/www/html /var/log/apache2 /var/run/apache2

COPY bin/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Privilege model: PID 1 runs as the unprivileged www-data user. Apache listens
# on 8080 (unprivileged), writes its PidFile/lock to /tmp, and logs to
# stdout/stderr — so no root is needed and the image satisfies runAsNonRoot +
# drop-ALL-caps + read-only-rootfs (with /tmp as tmpfs). Render's PORT model
# maps the platform port to 8080.
EXPOSE 8080
USER www-data
CMD ["start.sh"]
