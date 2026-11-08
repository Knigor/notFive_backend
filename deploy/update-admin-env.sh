#!/bin/sh
set -eu
cd /opt/notfive

test -f .env.production
IFS= read -r admin_username
IFS= read -r admin_password

if ! printf '%s\n' "$admin_username" | grep -Eq '^[a-z][a-z0-9_]{2,63}$'; then
    echo 'Invalid administrator username.' >&2
    exit 1
fi

if ! printf '%s\n' "$admin_password" | grep -Eq '^[A-Za-z0-9_@.:-]{12,128}$'; then
    echo 'Invalid administrator password format.' >&2
    exit 1
fi

if IFS= read -r unexpected; then
    echo 'Unexpected administrator configuration input.' >&2
    exit 1
fi

umask 077
tmp="$(mktemp .env.production.XXXXXX)"
trap 'if [ -e "$tmp" ]; then unlink "$tmp"; fi' EXIT
awk '!/^(ADMIN_BOOTSTRAP_USERNAME|ADMIN_BOOTSTRAP_PASSWORD)=/' .env.production > "$tmp"
printf 'ADMIN_BOOTSTRAP_USERNAME=%s\nADMIN_BOOTSTRAP_PASSWORD=%s\n' "$admin_username" "$admin_password" >> "$tmp"
chmod 600 "$tmp"
mv "$tmp" .env.production
