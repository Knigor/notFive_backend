#!/bin/sh
set -eu
cd /opt/notfive

test -f .env.production
IFS= read -r jwt_secret

if ! printf '%s\n' "$jwt_secret" | grep -Eq '^[a-f0-9]{64}$'; then
    echo 'JWT_SECRET must be 64 hexadecimal characters.' >&2
    exit 1
fi

if IFS= read -r unexpected; then
    echo 'Unexpected JWT configuration input.' >&2
    exit 1
fi

umask 077
tmp="$(mktemp .env.production.XXXXXX)"
trap 'if [ -e "$tmp" ]; then unlink "$tmp"; fi' EXIT
awk '!/^JWT_SECRET=/' .env.production > "$tmp"
printf 'JWT_SECRET=%s\n' "$jwt_secret" >> "$tmp"
chmod 600 "$tmp"
mv "$tmp" .env.production
