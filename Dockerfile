# PHP-FPM image for the IPTV service (app, queue worker and scheduler all use it).
FROM php:8.4-fpm-alpine

# PHP extensions Laravel + MySQL need; pcntl lets queue:work stop jobs cleanly on timeout
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl zip pcntl bcmath opcache
# mysqldump for iptv:backup
RUN apk add --no-cache mariadb-client mariadb-connector-c

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-iptv.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

# Bake the code in so the image also runs without a bind mount
COPY . .
RUN composer install --no-interaction --prefer-dist --no-progress \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
