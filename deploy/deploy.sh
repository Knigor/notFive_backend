#!/bin/sh
set -eu
cd /opt/notfive

valid_image() {
    printf '%s\n' "$1" | grep -Eq '^[a-z0-9][a-z0-9._/-]*(:[a-zA-Z0-9_][a-zA-Z0-9_.-]*|@sha256:[a-f0-9]{64})$'
}
usage() {
    echo 'Usage: deploy.sh APP_IMAGE [--mailer-from-stdin | --config-from-stdin DIGEST_IMAGE]' >&2
    exit 1
}
[ "$#" -ge 1 ] && valid_image "$1" || usage
target_app="$1"
mode="${2:-}"
target_digest=''
case "$#:$mode" in
    1:) ;;
    2:--mailer-from-stdin) ;;
    3:--config-from-stdin) target_digest="$3"; valid_image "$target_digest" || usage ;;
    *) usage ;;
esac
test -f .env.production
command -v python3 >/dev/null

# Configuration changes and container replacement share the same deployment lock.
exec 9>.deploy.lock
flock -n 9 || { echo 'Another deployment is running.' >&2; exit 1; }
umask 077
backup="$(mktemp .env.production.backup.XXXXXX)"
cp .env.production "$backup"
chmod 600 "$backup"
changed=0
containers_changed=0
success=0
digest_network=''
active_app="$target_app"
active_digest="$target_digest"

set_network() {
    digest_network=''
    if python3 deploy/update-production-env.py --uses-digest-network .env.production; then
        digest_network=1
    fi
}
compose() {
    if [ -n "$digest_network" ]; then
        APP_IMAGE="$active_app" docker compose --env-file .env.production -f compose.prod.yaml -f compose.digest.yaml --profile telegram --profile worker "$@"
    else
        APP_IMAGE="$active_app" docker compose --env-file .env.production -f compose.prod.yaml --profile telegram --profile worker "$@"
    fi
}
go_compose() {
    DIGEST_IMAGE="$active_digest" docker compose --env-file /opt/notfive/.env.production -f services/digest/compose.prod.yaml "$@"
}
container_for() {
    docker ps -aq --filter "label=com.docker.compose.project=$1" --filter "label=com.docker.compose.service=$2" --filter 'label=com.docker.compose.oneoff=False'
}
image_of() {
    if [ -n "$1" ]; then docker inspect --format '{{.Config.Image}}' "$1"; fi
}
running() {
    if [ -n "$1" ]; then docker inspect --format '{{.State.Running}}' "$1"; fi
}
stop_service() {
    stop_id="$(container_for "$1" "$2")" || return 1
    if [ -n "$stop_id" ]; then docker stop "$stop_id" >/dev/null; fi
}
restore_app_service() {
    restore_service="$1"
    restore_image="$2"
    restore_running="$3"
    if [ -n "$restore_image" ]; then
        active_app="$restore_image"
        if [ "$restore_running" = true ]; then
            compose up -d --no-build --no-deps --wait --wait-timeout 120 "$restore_service" || return 1
        else
            compose up --no-start --no-build --no-deps "$restore_service" || return 1
        fi
    fi
}
rollback() {
    echo 'Deployment failed; restoring previous configuration and containers.' >&2
    # Keep the backup if rollback itself fails, to allow manual recovery.
    restore_file="$(mktemp .env.production.restore.XXXXXX)" || return 1
    cp "$backup" "$restore_file" && chmod 600 "$restore_file" && mv "$restore_file" .env.production || return 1
    set_network
    [ "$containers_changed" -eq 1 ] || return 0
    rollback_failed=0
    stop_service notfive digest_worker || rollback_failed=1
    stop_service notfive telegram || rollback_failed=1
    stop_service notfive app || rollback_failed=1
    if [ "$mode" = '--config-from-stdin' ]; then
        stop_service notfive-digest digest || rollback_failed=1
        if [ -n "$previous_digest" ]; then
            active_digest="$previous_digest"
            if [ "$digest_running" = true ]; then
                go_compose up -d --no-build --wait --wait-timeout 120 digest || rollback_failed=1
            else
                go_compose up --no-start --no-build --no-deps digest || rollback_failed=1
            fi
        fi
    fi
    restore_app_service app "$previous_app" "$app_running" || rollback_failed=1
    restore_app_service telegram "$previous_bot" "$bot_running" || rollback_failed=1
    restore_app_service digest_worker "$previous_worker" "$worker_running" || rollback_failed=1
    return "$rollback_failed"
}
finish() {
    result="$?"
    trap - EXIT HUP INT TERM
    if [ "$success" -ne 1 ] && [ "$changed" -eq 1 ]; then
        if ! rollback; then
            echo "Rollback was incomplete; the previous environment remains in $backup. Database migrations are not reverted." >&2
            exit 1
        fi
        echo 'Previous environment restored. Database migrations, if applied, are not reverted.' >&2
    fi
    rm -f "$backup"
    exit "$result"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' HUP TERM

# Read actual running images; the env file can contain a tag from an older manual deploy.
app_id="$(container_for notfive app)"
bot_id="$(container_for notfive telegram)"
worker_id="$(container_for notfive digest_worker)"
digest_id="$(container_for notfive-digest digest)"
previous_app="$(image_of "$app_id")"; app_running="$(running "$app_id")"
previous_bot="$(image_of "$bot_id")"; bot_running="$(running "$bot_id")"
previous_worker="$(image_of "$worker_id")"; worker_running="$(running "$worker_id")"
previous_digest="$(image_of "$digest_id")"; digest_running="$(running "$digest_id")"

changed=1
case "$mode" in
    --config-from-stdin)
        python3 deploy/update-production-env.py .env.production "$target_app" "$target_digest"
        ;;
    --mailer-from-stdin) sh deploy/update-mailer-env.sh ;;
esac
set_network
if ! compose config --quiet >/dev/null 2>&1; then
    echo 'Invalid application Compose configuration.' >&2
    exit 1
fi
if [ "$mode" = '--config-from-stdin' ]; then
    if ! go_compose config --quiet >/dev/null 2>&1; then
        echo 'Invalid digest Compose configuration.' >&2
        exit 1
    fi
fi

# Download both images before stopping any existing process.
compose pull app
if [ "$mode" = '--config-from-stdin' ]; then go_compose pull digest; fi
containers_changed=1
stop_service notfive telegram
stop_service notfive digest_worker
if [ "$mode" = '--config-from-stdin' ]; then
    go_compose up -d --no-build --wait --wait-timeout 120 digest
fi
compose up -d --no-build --wait --wait-timeout 120 database app
compose exec -T app app-migrate
if [ "$mode" = '--config-from-stdin' ]; then
    compose exec -T app php -r '$r = @file_get_contents(getenv("GO_SERVICE_URL")."/healthz", false, stream_context_create(["http" => ["timeout" => 5]])); exit($r !== false && json_decode($r, true) === ["status" => "ok"] ? 0 : 1);'
fi
compose run -T --rm --no-deps telegram php bin/console telegram:poll --once
compose run -T --rm --no-deps telegram php bin/console telegram:configure-profile
compose up -d --no-build --no-deps --wait --wait-timeout 120 telegram
compose up -d --no-build --no-deps --wait --wait-timeout 120 digest_worker

# The unified updater already persisted both exact images; preserve legacy CLI behaviour.
if [ "$mode" != '--config-from-stdin' ]; then
    temporary="$(mktemp .env.production.XXXXXX)"
    awk -v image="$target_app" '!/^APP_IMAGE=/ { print } END { print "APP_IMAGE=" image }' .env.production > "$temporary"
    mv "$temporary" .env.production
fi
if [ -n "$previous_app" ]; then printf '%s\n' "$previous_app" > .image.previous; fi
if [ -n "$previous_digest" ]; then printf '%s\n' "$previous_digest" > .digest-image.previous; fi
success=1
printf 'Deployed %s\n' "$target_app"
if [ -n "$target_digest" ]; then printf 'Deployed %s\n' "$target_digest"; fi
