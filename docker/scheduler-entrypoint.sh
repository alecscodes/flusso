#!/bin/sh
set -e

cd /var/www || exit 1

# Check if the web service is ready
while ! curl -f http://web:80/up >/dev/null 2>&1; do
    echo "Waiting for web service to be ready..."
    sleep 5
done

echo "Starting Laravel scheduler..."
exec php artisan schedule:work --no-interaction
