#!/bin/sh
set -eu

if [ "$1" = 'apache2-foreground' ]; then
    mkdir -p /app/var/cache /app/var/log
    chown -R www-data:www-data /app/var
    runuser -u www-data -- php bin/console cache:warmup --no-interaction
    if [ "${APP_AUTO_MIGRATE:-0}" = '1' ]; then
        runuser -u www-data -- app-migrate --skip-admin
    fi
fi

exec docker-php-entrypoint "$@"
