#!/bin/sh
set -eu

cd /app
mode=${1:-}
set -- migrations/Version*.php

if [ -e "$1" ]; then
    php bin/console doctrine:migrations:migrate --no-interaction
else
    php bin/console doctrine:migrations:status --no-interaction
fi

php bin/console doctrine:fixtures:load --group=demo-digest --append --no-interaction

if [ "$mode" != '--skip-admin' ]; then
    php bin/console admin:bootstrap --no-interaction
fi
