#!/bin/sh
set -eu
cd /opt/notfive

if [ "$#" -ne 1 ] || ! printf '%s\n' "$1" | grep -Eq '^[a-z0-9][a-z0-9._/-]*(:[a-zA-Z0-9_][a-zA-Z0-9_.-]*|@sha256:[a-f0-9]{64})$'; then
    echo 'Usage: sh deploy/deploy.sh dockerhub-user/notfive:version (or image@sha256:digest)' >&2
    exit 1
fi
test -f .env.production

# Serialize manual and CI deployments on this server.
exec 9>.deploy.lock
flock -n 9 || { echo 'Another deployment is running.' >&2; exit 1; }

export APP_IMAGE="$1"
compose() { docker compose --env-file .env.production -f compose.prod.yaml "$@"; }

previous=''
container="$(compose ps --all --quiet app)"
if [ -n "$container" ]; then
    previous="$(docker inspect --format '{{.Config.Image}}' "$container")"
fi

compose config --quiet
compose pull app
if ! compose up -d --no-build --wait --wait-timeout 120; then
    compose logs --tail=80 app >&2
    if [ -n "$previous" ]; then
        echo "Deployment failed; restoring $previous" >&2
        APP_IMAGE="$previous" compose up -d --no-build --wait --wait-timeout 120 app
    fi
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
