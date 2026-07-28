#!/usr/bin/env bash

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/liveapp/current/liveapp laravel}"
BRANCH="${BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php8.3}"

SERVICES=(
  php8.3-fpm
  nginx
  liveapp-queue.service
  liveapp-scheduler.service
)

echo "==> Laravel deploy starting"
echo "    app dir:   $APP_DIR"
echo "    branch:    $BRANCH"
echo "    php bin:   $PHP_BIN"

cd "$APP_DIR"

echo "==> Pulling latest code"
git pull origin "$BRANCH"

echo "==> Clearing and rebuilding Laravel caches"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan config:cache

echo "==> Restarting services"
for service in "${SERVICES[@]}"; do
  sudo systemctl restart "$service"
done

echo "==> Deploy complete"
echo "==> LiveKit ws_url config:"
"$PHP_BIN" artisan tinker --execute="dump(config('services.livekit.ws_url'));"
