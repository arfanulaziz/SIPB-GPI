FROM php:8.2-apache

# ---------------------------------------------------------------------------
# Force exactly ONE MPM (prefork).
# a2enmod/a2dismod can leave conflicting mpm_*.load symlinks behind, which
# makes Apache abort with "AH00534: More than one MPM loaded". Managing the
# symlinks directly is deterministic. The trailing `ls | grep mpm` prints the
# result into the build log so we can verify only one MPM is enabled.
# ---------------------------------------------------------------------------
RUN rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf && \
    ln -s ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load && \
    ln -s ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf && \
    ls -l /etc/apache2/mods-enabled/ | grep mpm

# PHP extensions + URL rewriting
RUN docker-php-ext-install mysqli pdo pdo_mysql && a2enmod rewrite

WORKDIR /var/www/html

# Application code. public_html/ becomes the document root.
COPY public_html/ /var/www/html/
COPY database/ /var/www/html/database/

# ---------------------------------------------------------------------------
# config.php resolves .env as __DIR__/../../.env — from /var/www/html/config
# that is /var/.env, NOT /var/www/html/.env.
# The file is left EMPTY on purpose: it satisfies the file_exists() check in
# config.php, while parse_ini_file() returns [] so nothing is putenv()'d over
# the real DB_* / APP_* variables that Railway injects into the environment.
# ---------------------------------------------------------------------------
RUN touch /var/.env

RUN mkdir -p /var/www/html/uploads && \
    chmod 775 /var/www/html/uploads && \
    chown -R www-data:www-data /var/www/html /var/.env

RUN printf "upload_max_filesize=10M\npost_max_size=10M\nmemory_limit=256M\n" \
      > /usr/local/etc/php/conf.d/app.ini && \
    echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && \
    a2enconf servername

# Railway routes traffic to $PORT, so Apache has to listen there instead of 80.
CMD ["sh", "-c", "sed -ri \"s/^Listen .*/Listen ${PORT:-80}/\" /etc/apache2/ports.conf && sed -ri \"s/<VirtualHost \\*:[0-9]+>/<VirtualHost *:${PORT:-80}>/\" /etc/apache2/sites-available/000-default.conf && exec apache2-foreground"]
