FROM php:8.2-cli

# No Apache at all: the php:*-apache image kept aborting with
# "AH00534: More than one MPM loaded" and Apache buys us nothing here.
# This container only serves the Railway staging environment; the real
# production target (ctkry.salimagro.com) runs its own Apache.
RUN docker-php-ext-install mysqli pdo pdo_mysql

WORKDIR /var/www/html

COPY public_html/ /var/www/html/
COPY database/ /var/www/html/database/

# config.php resolves .env as __DIR__/../../.env — from /var/www/html/config
# that is /var/.env, NOT /var/www/html/.env.
# Left EMPTY on purpose: it satisfies the file_exists() check in config.php,
# while parse_ini_file() returns [] so nothing is putenv()'d over the real
# DB_* / APP_* variables Railway injects into the environment.
RUN touch /var/.env

RUN mkdir -p /var/www/html/uploads && chmod 777 /var/www/html/uploads

RUN printf "upload_max_filesize=10M\npost_max_size=10M\nmemory_limit=256M\n" \
      > /usr/local/etc/php/conf.d/app.ini

# Handle a few requests concurrently instead of one at a time.
ENV PHP_CLI_SERVER_WORKERS=4

# Railway routes traffic to $PORT.
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t /var/www/html"]
