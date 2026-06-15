#!/bin/sh
set -e

[ -f .env ] || cp .env.example .env

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1 && [ -f docker-compose.yml ]; then
	docker compose up -d --build --remove-orphans
	docker compose exec -T app php artisan app:deploy
else
	[ -f vendor/autoload.php ] || composer install --no-dev --optimize-autoloader --no-interaction
	php artisan app:deploy
	DIR=$(pwd)
	add_cron() { crontab -l 2>/dev/null | grep -q "$1" || (
		crontab -l 2>/dev/null
		echo "$2"
	) | crontab -; }
	add_cron 'schedule:run' "* * * * * cd $DIR && php artisan schedule:run >> /dev/null 2>&1"
	add_cron 'queue:work' "* * * * * cd $DIR && php artisan queue:work --stop-when-empty --tries=3 --timeout=90 >> /dev/null 2>&1"
fi
