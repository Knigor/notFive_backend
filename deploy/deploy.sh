#!/bin/sh
set -eu
cd /opt/notfive

if [ "$#" -lt 1 ] || [ "$#" -gt 2 ] || { [ "$#" -eq 2 ] && [ "$2" != '--mailer-from-stdin' ]; } || ! printf '%s\n' "$1" | grep -Eq '^[a-z0-9][a-z0-9._/-]*(:[a-zA-Z0-9_][a-zA-Z0-9_.-]*|@sha256:[a-f0-9]{64})$'; then
    echo 'Usage: sh deploy/deploy.sh ghcr.io/owner/repository:version (or image@sha256:digest) [--mailer-from-stdin]' >&2
    exit 1
fi
test -f .env.production

# Serialize manual and CI deployments on this server.
exec 9>.deploy.lock
flock -n 9 || { echo 'Another deployment is running.' >&2; exit 1; }

if [ "$#" -eq 2 ]; then
    sh deploy/update-mailer-env.sh
fi

export APP_IMAGE="$1"
compose() { docker compose --env-file .env.production -f compose.prod.yaml "$@"; }
bot_compose() { docker compose --env-file .env.production -f compose.prod.yaml --profile telegram "$@"; }

previous=''
bot_was_running=''
container="$(compose ps --all --quiet app)"
if [ -n "$container" ]; then
    previous="$(docker inspect --format '{{.Config.Image}}' "$container")"
fi
bot_container="$(bot_compose ps --all --quiet telegram)"
if [ -n "$bot_container" ] && [ "$(docker inspect --format '{{.State.Running}}' "$bot_container")" = true ]; then
    bot_was_running=1
fi

restore_previous() {
    if [ -n "$previous" ]; then
        echo "Deployment failed; restoring $previous" >&2
        APP_IMAGE="$previous" compose up -d --no-build --wait --wait-timeout 120 app
        if [ -n "$bot_was_running" ]; then
            APP_IMAGE="$previous" bot_compose up -d --no-build --wait --wait-timeout 120 telegram
        fi
    fi
}

compose config --quiet
compose pull app
bot_compose stop telegram
if ! compose up -d --no-build --wait --wait-timeout 120; then
    compose logs --tail=80 app >&2
    restore_previous
    exit 1
fi

if ! compose exec -T app app-migrate; then
    restore_previous
    exit 1
fi

if ! bot_compose run -T --rm --no-deps telegram php bin/console telegram:poll --once; then
    restore_previous
    exit 1
fi

if ! bot_compose run -T --rm --no-deps telegram php bin/console telegram:configure-profile; then
    restore_previous
    exit 1
fi

if ! bot_compose up -d --no-build --wait --wait-timeout 120 telegram; then
    bot_compose logs --tail=80 telegram >&2
    restore_previous
    exit 1
fi

# Persist the successfully deployed image for subsequent manual Compose commands.
umask 077
tmp="$(mktemp .env.production.XXXXXX)"
trap 'rm -f "$tmp"' EXIT
awk -v image="$APP_IMAGE" '!/^APP_IMAGE=/ { print } END { print "APP_IMAGE=" image }' .env.production > "$tmp"
mv "$tmp" .env.production
if [ -n "$previous" ]; then printf '%s\n' "$previous" > .image.previous; fi
printf 'Deployed %s\n' "$APP_IMAGE"
