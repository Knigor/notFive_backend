#!/bin/sh
set -eu
export GRAPHQL_SMOKE_URL="${1:-http://127.0.0.1:8080/api/graphql}"

docker compose -f compose.prod.yaml exec -T app php bin/console admin:bootstrap --no-interaction
docker compose -f compose.prod.yaml exec -T app php bin/console admin:bootstrap --no-interaction

python3 - <<'PY'
import json
import os
import urllib.request

url = os.environ['GRAPHQL_SMOKE_URL']


def graphql(query, variables=None, token=None):
    headers = {'Content-Type': 'application/json'}
    if token is not None:
        headers['Authorization'] = 'Bearer ' + token
    request = urllib.request.Request(
        url,
        data=json.dumps({'query': query, 'variables': variables}).encode(),
        headers=headers,
        method='POST',
    )
    with urllib.request.urlopen(request) as response:
        return json.load(response)


assert graphql('query { ping }') == {'data': {'ping': 1}}
assert 'errors' in graphql('query { availableSiteSources { id } }')
assert 'errors' in graphql('mutation { startRun(input: { controlTime: "2026-11-08T12:00:00+03:00" }) { runId } }')
assert graphql('mutation ($input: AdminLoginInput!) { login(input: $input) { token } }', {
    'input': {'username': 'invalid!', 'password': 'bad'}
})['errors']
assert graphql('mutation ($input: AdminLoginInput!) { login(input: $input) { token } }', {
    'input': {'username': 'ci_admin', 'password': 'incorrect-password'}
})['errors']
result = graphql('mutation ($input: AdminLoginInput!) { login(input: $input) { token expiresIn } }', {
    'input': {'username': 'ci_admin', 'password': 'ci-only-admin-password-2026'}
})
token = result['data']['login']['token']
assert result['data']['login']['expiresIn'] == 3600
assert len(token.split('.')) == 3
assert len(graphql('query { availableSiteSources { id } }', token=token)['data']['availableSiteSources']) == 28
print('GraphQL administrator authentication passed.')
PY

test "$(curl --silent --output /dev/null --write-out '%{http_code}' \
    -X POST "$GRAPHQL_SMOKE_URL" \
    -H 'Content-Type: application/json' \
    -H 'Authorization: Bearer invalid.token.value' \
    --data '{"query":"query { availableSiteSources { id } }"}')" = 401
