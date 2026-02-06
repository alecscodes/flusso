#!/bin/sh
set -e

# Wait for PHP-FPM to be ready (depends_on only waits for container, not service)
until nc -z app 9000 2>/dev/null; do
    sleep 0.5
done

# Ensure nginx log directory exists
mkdir -p /var/log/nginx

# Test configuration before starting
nginx -t

exec nginx -g 'daemon off;'
