#!/bin/sh
set -e

cd "$(dirname "$0")"
DEPLOY_DIR="$(pwd)"
DEPLOY_SCRIPT="$DEPLOY_DIR/deploy.sh"

if [ "${1:-}" = "--if-outdated" ]; then
    git fetch origin 2>/dev/null || exit 0
    [ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] && exit 0
    exec "$DEPLOY_SCRIPT"
fi

git fetch origin
git reset --hard origin/main
git clean -fd

[ -f .env ] || cp .env.example .env

{ grep -Fv 'APP_COMMIT=' .env; echo "APP_COMMIT=$(git rev-parse HEAD)"; } > .env.tmp && mv .env.tmp .env

mkdir -p database
[ -f database/database.sqlite ] || touch database/database.sqlite

if docker info >/dev/null 2>&1; then
    grep -q '^APP_KEY=.\+' .env 2>/dev/null || {
        KEY="base64:$(head -c 32 /dev/urandom | base64)"
        sed -i "s|^APP_KEY=$|APP_KEY=$KEY|" .env 2>/dev/null || echo "APP_KEY=$KEY" >> .env
    }

    docker compose up -d --build --remove-orphans
    artisan() { docker compose exec -T web php artisan "$@"; }
else
    composer install --no-dev --optimize-autoloader --no-interaction
    npm ci --prefer-offline --no-audit
    npm run build
    grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force
    artisan() { php artisan "$@"; }

    ARTISAN="$DEPLOY_DIR/artisan"
    (
        crontab -l 2>/dev/null |
        grep -Fv "$ARTISAN schedule:run" |
        grep -Fv "$ARTISAN queue:work" || true

        echo "* * * * * cd $DEPLOY_DIR && php $ARTISAN schedule:run >/dev/null 2>&1"
        echo "* * * * * cd $DEPLOY_DIR && flock -n /tmp/$(basename "$DEPLOY_DIR")-queue.lock php $ARTISAN queue:work --sleep=3 --tries=3 --timeout=3600 >/dev/null 2>&1"
    ) | crontab -
fi

if ! crontab -l 2>/dev/null | grep -qF "$DEPLOY_SCRIPT"; then
    (crontab -l 2>/dev/null; echo "*/5 * * * * cd $DEPLOY_DIR && $DEPLOY_SCRIPT --if-outdated >/dev/null 2>&1") | crontab -
fi

artisan migrate --force --no-interaction
artisan optimize
artisan reload
