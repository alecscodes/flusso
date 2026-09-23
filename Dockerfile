FROM dunglas/frankenphp:php8.4 AS base
RUN install-php-extensions pcntl intl zip
RUN apt-get update && apt-get install -y --no-install-recommends procps && rm -rf /var/lib/apt/lists/*
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction

FROM base AS assets
RUN apt-get update && apt-get install -y --no-install-recommends nodejs npm
RUN npm ci && npm run build

FROM base
COPY --from=assets /app/public/build public/build
CMD ["frankenphp", "php-server", "-r", "public/"]
