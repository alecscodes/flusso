#!/bin/sh
set -e

# Ensure we're in the correct directory
cd /var/www || exit 1

# Helper function to set permissions
set_permissions() {
    local path=$1
    local owner=${2:-www-data:www-data}
    local mode=${3:-775}

    [ -e "$path" ] || return 0
    chown -R "$owner" "$path" 2>/dev/null || true
    chmod -R "$mode" "$path" 2>/dev/null || true
}

# Git config for docker
[ -d "/var/www/.git" ] && git config --global --add safe.directory /var/www 2>/dev/null || true

# Ensure required directories exist
mkdir -p storage/framework/{cache,sessions,views,testing} storage/app/{private,public} storage/logs bootstrap/cache public/build

# Set permissions for writable directories
set_permissions storage
set_permissions bootstrap/cache
set_permissions public/build 755

# Setup SQLite database
[ "${DB_CONNECTION}" = "sqlite" ] || [ -z "${DB_CONNECTION}" ] && {
    [ ! -f /var/www/database/database.sqlite ] && touch /var/www/database/database.sqlite
    set_permissions /var/www/database
}

# Install dependencies if missing (for volume mounts)
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
npm ci --prefer-offline --no-audit

# Build assets
npm run build

# Generate APP_KEY if missing
grep -q "APP_KEY=base64:" .env 2>/dev/null || php artisan key:generate --force || true

# Run migrations
php artisan migrate --force

# Clear caches
php artisan optimize:clear || true

# Optimize for production
php artisan optimize || true

# Fix permissions after optimize (creates files as root)
set_permissions bootstrap/cache

# Ensure .gitignore remains writable after optimize (git needs it for deployments)
[ -f bootstrap/cache/.gitignore ] && chown www-data:www-data bootstrap/cache/.gitignore 2>/dev/null || true
[ -f bootstrap/cache/.gitignore ] && chmod 664 bootstrap/cache/.gitignore 2>/dev/null || true

exec php-fpm
