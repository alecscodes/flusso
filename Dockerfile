FROM php:8.4-fpm-alpine AS builder

WORKDIR /var/www

RUN apk add --no-cache $PHPIZE_DEPS linux-headers oniguruma-dev libzip-dev icu-dev sqlite-dev unzip nodejs npm curl \
    && docker-php-ext-install -j$(nproc) mbstring zip intl pdo pdo_sqlite sockets \
    && apk del $PHPIZE_DEPS linux-headers \
    && rm -rf /tmp/* /var/cache/apk/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist \
    && npm ci && npm run build && rm -rf node_modules

FROM php:8.4-fpm-alpine AS app

RUN apk add --no-cache nginx wget oniguruma libzip icu-libs sqlite-libs \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo 'pm.status_path = /status' >> /usr/local/etc/php-fpm.d/www.conf

COPY docker/nginx/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

COPY --from=builder /usr/local/lib/php/extensions   /usr/local/lib/php/extensions
COPY --from=builder /usr/local/etc/php/conf.d       /usr/local/etc/php/conf.d
COPY --from=builder /usr/local/bin/docker-php-ext-* /usr/local/bin/
COPY --chown=www-data:www-data --from=builder /var/www /var/www

WORKDIR /var/www

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
EXPOSE 80
CMD ["php-fpm"]
