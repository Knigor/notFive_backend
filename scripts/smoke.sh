#!/bin/sh
set -eu

base_url="${1:-http://127.0.0.1:8080}"
curl --fail --silent --show-error "$base_url/" | grep -q 'Добро'
test "$(curl --fail --silent --show-error "$base_url/healthz")" = '{"status":"ok"}'
test "$(curl --silent --output /dev/null --write-out '%{http_code}' "$base_url/does-not-exist")" = 404
test "$(curl --silent --output /dev/null --write-out '%{http_code}' "$base_url/.env")" = 404
test "$(curl --silent --output /dev/null --write-out '%{http_code}' -X POST "$base_url/")" = 405
printf 'Smoke checks passed: homepage, database health, 404, private files, HTTP methods.\n'
