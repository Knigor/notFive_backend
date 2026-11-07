#!/bin/sh
set -eu

base_url="${1:-http://127.0.0.1:8080}"

curl --fail --silent --show-error -X POST "$base_url/api/graphql" \
    -H 'Content-Type: application/json' \
    --data '{"query":"query Ping { ping }"}' \
    | python3 -c 'import json,sys; assert json.load(sys.stdin) == {"data": {"ping": 1}}'

printf 'GraphQL ping passed.\n'
