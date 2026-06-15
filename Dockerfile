FROM php:8.4-fpm-alpine

WORKDIR /var/www

RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS linux-headers oniguruma-dev libzip-dev sqlite-dev freetype-dev harfbuzz \
    && apk add --no-cache \
    oniguruma libzip sqlite unzip curl git nodejs npm nss freetype \
    ca-certificates ttf-freefont gcompat nginx netcat-openbsd \
    && docker-php-ext-install mbstring zip pdo pdo_sqlite sockets \
    && apk del .build-deps

RUN npm install -g npm@latest

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY docker/scheduler-entrypoint.sh docker/queue-entrypoint.sh docker/web-entrypoint.sh /
RUN chmod +x /scheduler-entrypoint.sh /queue-entrypoint.sh /web-entrypoint.sh

COPY . .

CMD ["php-fpm"]
