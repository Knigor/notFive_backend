#!/bin/sh
set -eu
cd /opt/notfive

test -f .env.production
IFS= read -r bot_token

if [ -z "$bot_token" ]; then
    echo 'Telegram configuration is missing.' >&2
    exit 1
fi

case "$bot_token" in
    *[!a-zA-Z0-9_:-]*) echo 'Telegram configuration has invalid characters.' >&2; exit 1 ;;
esac

if IFS= read -r unexpected; then
    echo 'Unexpected Telegram configuration input.' >&2
    exit 1
fi

umask 077
tmp="$(mktemp .env.production.XXXXXX)"
trap 'if [ -e "$tmp" ]; then unlink "$tmp"; fi' EXIT
awk '!/^TELEGRAM_BOT_TOKEN=/' .env.production > "$tmp"
printf 'TELEGRAM_BOT_TOKEN=%s\n' "$bot_token" >> "$tmp"
chmod 600 "$tmp"
mv "$tmp" .env.production
