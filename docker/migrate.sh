#!/bin/sh
set -eu

cd /app
set -- migrations/Version*.php

if [ -e "$1" ]; then
    php bin/console doctrine:migrations:migrate --no-interaction
else
    php bin/console doctrine:migrations:status --no-interaction
fi

php bin/console admin:bootstrap --no-interaction
