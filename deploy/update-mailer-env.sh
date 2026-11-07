#!/bin/sh
set -eu
cd /opt/notfive

test -f .env.production
IFS= read -r mailer_dsn
IFS= read -r mail_from_address

if [ -z "$mailer_dsn" ] || [ -z "$mail_from_address" ] || [ "$mailer_dsn" = 'null://null' ]; then
    echo 'Mail configuration is missing.' >&2
    exit 1
fi

case "$mailer_dsn$mail_from_address" in
    *[[:space:]]*) echo 'Mail configuration contains whitespace.' >&2; exit 1 ;;
esac

if IFS= read -r unexpected; then
    echo 'Unexpected mail configuration input.' >&2
    exit 1
fi

umask 077
tmp="$(mktemp .env.production.XXXXXX)"
trap 'if [ -e "$tmp" ]; then unlink "$tmp"; fi' EXIT
awk '!/^(MAILER_DSN|MAIL_FROM_ADDRESS)=/' .env.production > "$tmp"
printf 'MAILER_DSN=%s\nMAIL_FROM_ADDRESS=%s\n' "$mailer_dsn" "$mail_from_address" >> "$tmp"
chmod 600 "$tmp"
mv "$tmp" .env.production
