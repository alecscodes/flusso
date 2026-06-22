#!/bin/sh
set -e

chown -R www-data:www-data /var/www/storage
chown -R www-data:www-data /var/www/database
chmod 664 /var/www/database/database.sqlite

if [ "$1" = "php-fpm" ]; then
    docker-php-entrypoint php-fpm &
    exec nginx -g 'daemon off;'
fi

exec docker-php-entrypoint "$@"
