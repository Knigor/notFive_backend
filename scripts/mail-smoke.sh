#!/bin/sh
set -eu

recipient="mail-smoke-$(date +%s)-$$@notfive.invalid"
mailpit_port="${MAILPIT_HTTP_PORT:-8025}"
mailpit_url="${MAILPIT_URL:-http://127.0.0.1:$mailpit_port}"
app_url="${APP_BASE_URL:-http://127.0.0.1:${HTTP_PORT:-8080}}"
subject="NotFive local digest"
digest="Digest period: today. One verified event."

test "$(curl --silent --output /dev/null --write-out '%{http_code}' \
    --header 'Content-Type: application/json' \
    --data '{"recipient":"invalid","subject":"Digest","digest":"Content"}' \
    "$app_url/api/digest-email/send")" = 400

preview="$(curl --fail --silent --show-error \
    --header 'Content-Type: application/json' \
    --data "{\"subject\":\"$subject\",\"digest\":\"$digest\"}" \
    "$app_url/api/digest-email/preview")"

python3 - "$preview" "$subject" "$digest" <<'PY'
import json
import sys

preview, subject, digest = sys.argv[1:]
data = json.loads(preview)
assert data['subject'] == subject
assert data['text'] == digest
assert subject in data['html']
assert digest in data['html']
PY

test "$(curl --fail --silent --show-error \
    --header 'Content-Type: application/json' \
    --data "{\"recipient\":\"$recipient\",\"subject\":\"$subject\",\"digest\":\"$digest\"}" \
    "$app_url/api/digest-email/send")" = '{"status":"sent"}'

python3 - "$mailpit_url" "$recipient" "$subject" "$digest" <<'PY'
import json
import sys
from urllib.request import urlopen

base_url, recipient, subject, digest = sys.argv[1:]

with urlopen(f'{base_url}/api/v1/messages', timeout=5) as response:
    messages = json.load(response)['messages']

message = next(
    (message for message in messages if any(address['Address'] == recipient for address in message['To'])),
    None,
)
if message is None:
    raise AssertionError(f'Mailpit did not receive a message for {recipient}')

with urlopen(f"{base_url}/api/v1/message/{message['ID']}", timeout=5) as response:
    stored_message = json.load(response)

if stored_message['Subject'] != subject:
    raise AssertionError('Unexpected test email subject')
if stored_message['Text'].strip() != digest:
    raise AssertionError('Unexpected test email body')
if digest not in stored_message['HTML']:
    raise AssertionError('Unexpected test email HTML')

print('Local mail smoke test passed.')
PY
