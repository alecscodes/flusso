FROM php:8.4-fpm-alpine AS app

WORKDIR /var/www

# Install dependencies
RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS linux-headers oniguruma-dev libzip-dev sqlite-dev freetype-dev harfbuzz \
    && apk add --no-cache \
    oniguruma libzip sqlite unzip curl git nodejs npm nss freetype \
    ca-certificates ttf-freefont gcompat nginx netcat-openbsd \
    && docker-php-ext-install mbstring zip pdo pdo_sqlite sockets \
    && apk del .build-deps

# Update npm globally
RUN npm install -g npm@latest

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy entrypoints
COPY docker/entrypoint.sh docker/scheduler-entrypoint.sh docker/queue-entrypoint.sh docker/web-entrypoint.sh /
RUN chmod +x /entrypoint.sh /scheduler-entrypoint.sh /queue-entrypoint.sh /web-entrypoint.sh

# Copy dependency files first
COPY composer.json composer.lock ./
COPY package*.json ./

# Install PHP dependencies
RUN composer install --no-scripts --no-dev --prefer-dist --no-progress --optimize-autoloader

# Install NPM dependencies
RUN npm ci --prefer-offline --no-audit

# Copy the full project
COPY . .

CMD ["php-fpm"]
