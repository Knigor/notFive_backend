#!/usr/bin/env python3
"""Check email subscriptions and digest publication through the local HTTP API.

Requirements: Python 3, Docker, a migrated PostgreSQL database whose name ends
with ``_smoke`` (no runs or delivery targets), an app using that database, and an
isolated Mailpit SMTP server. The app's DEFAULT_URI must use API_ROOT's port.
Bootstrap a test administrator first. No model or external SMTP is used.

Environment:
    API_ROOT                 http://127.0.0.1:8092
    API_FAILURE_ROOT         optional second app with the same DB/JWT and failing SMTP
    MAILPIT_URL              http://127.0.0.1:18025
    DATABASE_CONTAINER       notfive-dev-database-1
    DATABASE_USER            notfive
    TEST_DATABASE            notfive_email_smoke
    TEST_ADMIN_JWT           administrator JWT, or use both variables below
    TEST_ADMIN_USERNAME      test administrator login
    TEST_ADMIN_PASSWORD      test administrator password

Run sequentially: python3 -B tests/email-newsletter-smoke.py
Only this run's fixture IDs and captured Mailpit messages are removed on exit.
Credentials, confirmation links, and unsubscribe tokens are never printed.
"""

import hashlib
import html
import http.cookiejar
import json
import os
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from concurrent.futures import ThreadPoolExecutor
from html.parser import HTMLParser


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def quote(value):
    return "'" + str(value).replace("'", "''") + "'"


class Form(HTMLParser):
    def __init__(self, content):
        super().__init__()
        self.action = None
        self.method = None
        self.fields = {}
        self.feed(content)

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag == "form" and self.method is None:
            self.action = attributes.get("action", "")
            self.method = attributes.get("method", "get").lower()
        elif tag == "input" and attributes.get("name"):
            self.fields[attributes["name"]] = attributes.get("value", "")


class NewsletterSmoke:
    def __init__(self):
        self.api = os.getenv("API_ROOT", "http://127.0.0.1:8092").rstrip("/")
        self.failure_api = os.getenv("API_FAILURE_ROOT", "").rstrip("/")
        self.mailpit = os.getenv("MAILPIT_URL", "http://127.0.0.1:18025").rstrip("/")
        self.database = os.getenv("TEST_DATABASE", "notfive_email_smoke")
        self.database_container = os.getenv("DATABASE_CONTAINER", "notfive-dev-database-1")
        self.database_user = os.getenv("DATABASE_USER", "notfive")
        self.jwt = os.getenv("TEST_ADMIN_JWT", "")
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )
        self.run_ids = []
        self.target_ids = set()
        self.limit_ids = set()
        self.fixture_prefix = "newsletter-" + uuid.uuid4().hex
        self.emails = {
            name: f"{self.fixture_prefix}-{name}@example.invalid"
            for name in ("ru", "en", "pending", "late", "last_allowed", "over_limit")
        }
        self.body = (
            f"Сохранённый выпуск {self.fixture_prefix}.\n\n"
            "Проверенный факт: опубликовано одно исправление. Оговорка сохранена.\n"
            "Источник: https://example.invalid/security?x=1&y=2\n"
        )
        self.subject = f"notFive: {self.fixture_prefix}"

    def validate_environment(self):
        require(
            re.fullmatch(r"[A-Za-z0-9_]+_smoke", self.database),
            "TEST_DATABASE must be a dedicated database ending in _smoke",
        )
        for address in (self.api, self.mailpit, *([self.failure_api] if self.failure_api else [])):
            parsed = urllib.parse.urlsplit(address)
            require(
                parsed.scheme == "http"
                and parsed.hostname in ("127.0.0.1", "localhost", "::1")
                and not parsed.username
                and not parsed.password
                and parsed.path in ("", "/")
                and not parsed.query
                and not parsed.fragment,
                "API_ROOT, API_FAILURE_ROOT and MAILPIT_URL must be plain loopback HTTP origins",
            )
        require(
            self.jwt
            or (os.getenv("TEST_ADMIN_USERNAME") and os.getenv("TEST_ADMIN_PASSWORD")),
            "Provide TEST_ADMIN_JWT or TEST_ADMIN_USERNAME and TEST_ADMIN_PASSWORD",
        )
        require(
            self.sql("SELECT (SELECT COUNT(*) FROM runs) + (SELECT COUNT(*) FROM delivery_targets)") == "0",
            "The isolated test database must have no runs or delivery targets",
        )
        self.request(self.mailpit + "/api/v1/messages?limit=1")

    def sql(self, statement):
        result = subprocess.run(
            [
                "docker", "exec", "-i", self.database_container, "psql",
                "-X", "-q", "-A", "-t", "-v", "ON_ERROR_STOP=1",
                "-U", self.database_user, "-d", self.database,
            ],
            input=statement + ";\n", text=True, capture_output=True, check=False,
        )
        require(result.returncode == 0, "A PostgreSQL fixture/read/cleanup command failed")
        return result.stdout.strip()

    def rows(self, statement):
        return json.loads(self.sql(
            "SELECT COALESCE(json_agg(smoke_row), '[]'::json)::text FROM ("
            + statement + ") smoke_row"
        ))

    def request(self, url, *, method="GET", payload=None, form=None, locale="ru", admin=False):
        headers = {"Accept-Language": locale}
        data = None
        if payload is not None:
            headers["Content-Type"] = "application/json"
            data = json.dumps(payload).encode()
        if form is not None:
            headers["Content-Type"] = "application/x-www-form-urlencoded"
            data = urllib.parse.urlencode(form).encode()
        if admin:
            headers["Authorization"] = "Bearer " + self.jwt
        request = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            response = self.opener.open(request, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        except urllib.error.URLError:
            raise RuntimeError("A local HTTP request failed") from None
        with response:
            return response.status, response.read().decode("utf-8")

    def graphql(self, query, variables=None, *, locale="ru", admin=False, api=None):
        status, content = self.request(
            (api or self.api) + "/api/graphql", method="POST",
            payload={"query": query, "variables": variables or {}},
            locale=locale, admin=admin,
        )
        require(status in (200, 400), "Unexpected GraphQL HTTP status")
        try:
            return json.loads(content)
        except ValueError:
            raise AssertionError("GraphQL did not return JSON") from None

    def authenticate(self):
        if self.jwt:
            return
        result = self.graphql(
            "mutation($input: AdminLoginInput!) { login(input: $input) { token } }",
            {"input": {
                "username": os.environ["TEST_ADMIN_USERNAME"],
                "password": os.environ["TEST_ADMIN_PASSWORD"],
            }},
        )
        require(not result.get("errors"), "Test administrator login failed")
        self.jwt = result["data"]["login"]["token"]

    def fixture_run(self, *, status="completed", complete=True, published=False):
        run_id = self.sql(
            "INSERT INTO runs (id, control_time, status, model_version, prompt_version, "
            "settings_snapshot, published_at) VALUES (gen_random_uuid(), "
            "'2098-08-08T10:00:00+03:00', " + quote(status)
            + ", 'smoke-no-model', 'smoke-no-model', '{\"language\":\"ru\"}', "
            + ("CURRENT_TIMESTAMP" if published else "NULL") + ") RETURNING id"
        )
        self.run_ids.append(run_id)
        if complete:
            for kind, content in (
                ("digest", self.body), ("post", "Сохранённый короткий пост."),
                ("email_preview", self.body), ("journal", "id;status;group;reason;error\n"),
            ):
                artifact_id = self.sql(
                    "INSERT INTO artifacts (id, run_id, kind, content) VALUES "
                    f"(gen_random_uuid(), {quote(run_id)}, {quote(kind)}, {quote(content)}) RETURNING id"
                )
                if kind == "email_preview":
                    self.sql(
                        "INSERT INTO email_preview_details (artifact_id, subject) VALUES "
                        f"({quote(artifact_id)}, {quote(self.subject)})"
                    )
        return run_id

    def digest(self, api=None):
        result = self.graphql(
            '{ digest(date: "2098-08-08") { runId files { digest email emailSubject } } }',
            api=api,
        )
        require(not result.get("errors"), "Public digest query failed")
        return result["data"]["digest"]

    def confirm_database_binding(self):
        run_id = self.fixture_run(published=True)
        for api in (self.api, *([self.failure_api] if self.failure_api else [])):
            visible = self.digest(api)
            require(
                visible and visible["runId"] == run_id and visible["files"]["email"] == self.body,
                "An HTTP application is not reading the isolated TEST_DATABASE; no subscription was attempted",
            )
        self.sql("UPDATE runs SET published_at = NULL WHERE id = " + quote(run_id))
        require(self.digest() is None, "An unpublished fixture is publicly visible")
        return run_id

    def subscription(self, email):
        rows = self.rows(
            "SELECT id, status, confirmed_at, locale, email_confirmation_token_hash "
            "FROM delivery_targets WHERE channel = 'email' AND destination = " + quote(email)
        )
        require(len(rows) == 1, "Expected exactly one subscription for the test address")
        self.target_ids.add(rows[0]["id"])
        return rows[0]

    def subscribe(self, email, locale="ru"):
        before = {row["ip_hash"] for row in self.rows("SELECT ip_hash FROM email_subscription_limits")}
        try:
            return self.graphql(
                "mutation($email: String!) { subscribeEmail(email: $email) { status message } }",
                {"email": email}, locale=locale,
            )
        finally:
            after = {row["ip_hash"] for row in self.rows("SELECT ip_hash FROM email_subscription_limits")}
            self.limit_ids.update(after - before)

    def accepted(self, result):
        require(not result.get("errors"), "subscribeEmail rejected a valid test address")
        value = result["data"]["subscribeEmail"]
        require(value["status"] == "accepted" and value["message"], "Invalid subscription result")
        return value["message"]

    def messages(self, email=None):
        status, content = self.request(self.mailpit + "/api/v1/messages?limit=1000")
        require(status == 200, "Mailpit message listing failed")
        addresses = {email} if email else set(self.emails.values())
        return [message for message in json.loads(content)["messages"] if any(
            recipient["Address"].lower() in addresses for recipient in message["To"]
        )]

    def wait_messages(self, email, count):
        deadline = time.monotonic() + 10
        while True:
            messages = self.messages(email)
            if len(messages) >= count or time.monotonic() >= deadline:
                require(len(messages) == count, "Unexpected number of captured test messages")
                return messages
            time.sleep(0.1)

    def message(self, message_id):
        status, content = self.request(self.mailpit + "/api/v1/message/" + message_id)
        require(status == 200, "Mailpit message reading failed")
        return json.loads(content)

    def local_action_url(self, url):
        parsed = urllib.parse.urlsplit(url)
        origin = urllib.parse.urlsplit(self.api)
        require(
            parsed.scheme == origin.scheme
            and parsed.hostname in ("127.0.0.1", "localhost", "::1")
            and parsed.port == origin.port
            and not parsed.username and not parsed.password,
            "An email or form link does not point to the isolated HTTP application",
        )
        return self.api + parsed.path + ("?" + parsed.query if parsed.query else "")

    def email_link(self, message, action):
        content = html.unescape(message.get("Text", "") + "\n" + message.get("HTML", ""))
        links = re.findall(r'https?://[^\s<>"\']+/email/' + action + r'\?token=[0-9a-f]{64}', content)
        require(links, "Expected a capability link in the captured email")
        return self.local_action_url(links[0])

    def post_form(self, url, page, locale):
        form = Form(page)
        require(form.method == "post", "The capability page must display a POST form")
        action = self.local_action_url(urllib.parse.urljoin(url, form.action))
        status, _ = self.request(action, method="POST", form=form.fields, locale=locale)
        require(status == 200, "Confirmation/unsubscribe form submission failed")

    def confirm(self, name, locale, message_count=1):
        email = self.emails[name]
        messages = self.wait_messages(email, message_count)
        confirmation = self.message(messages[0]["ID"])
        link = self.email_link(confirmation, "confirm")
        token = urllib.parse.parse_qs(urllib.parse.urlsplit(link).query)["token"][0]
        pending = self.subscription(email)
        require(pending["status"] == "pending" and pending["confirmed_at"] is None, "Address was activated before confirmation")
        require(
            pending["email_confirmation_token_hash"] == hashlib.sha256(token.encode()).hexdigest(),
            "Confirmation capability must be stored as a hash",
        )
        status, page = self.request(link, locale=locale)
        require(status == 200, "Confirmation GET failed")
        require(f'lang="{locale}"' in page, "Confirmation page has the wrong language")
        require(self.subscription(email)["status"] == "pending", "Confirmation GET changed subscription state")
        self.post_form(link, page, locale)
        active = self.subscription(email)
        require(active["status"] == "active" and active["confirmed_at"], "Confirmation POST did not activate subscription")
        require(active["locale"] == locale, "Subscription language was not preserved")
        return link

    def publish(self, run_id, *, admin=True, api=None):
        return self.graphql(
            "mutation($runId: ID!) { publishDigest(runId: $runId) { "
            "runId publishedAt alreadyPublished emailSent emailFailed emailPending } }",
            {"runId": run_id}, admin=admin, api=api,
        )

    def published(self, result, run_id, repeated=False, *, sent=2, failed=0):
        require(not result.get("errors"), "publishDigest failed for a complete fixture")
        value = result["data"]["publishDigest"]
        require(value["runId"] == run_id and value["publishedAt"], "Missing publication metadata")
        require(value["alreadyPublished"] is repeated, "Unexpected publication idempotency flag")
        require(value["emailSent"] == sent and value["emailFailed"] == failed and value["emailPending"] == 0, "Unexpected delivery counters")
        return value

    def newsletter(self, name, count, locale):
        messages = self.wait_messages(self.emails[name], count)
        candidates = [self.message(message["ID"]) for message in messages if message["Subject"] == self.subject]
        require(candidates, "Saved email subject was not delivered")
        message = candidates[0]
        # MIME transports use CRLF; preserve paragraphs while comparing with stored LF text.
        text = message["Text"].replace("\r\n", "\n")
        require(text.startswith(self.body), "The delivered plain text changed the saved artifact")
        require(html.escape(self.body).splitlines()[0] in message["HTML"], "The HTML email lost the saved artifact")
        label = "Отписаться" if locale == "ru" else "Unsubscribe"
        require(label.lower() in message["Text"].lower(), "Unsubscribe footer has the wrong language")
        return self.email_link(message, "unsubscribe")

    def run(self):
        self.validate_environment()
        self.authenticate()
        first = self.confirm_database_binding()
        print("PASS: HTTP application is bound to the isolated fixture database")

        invalid = [self.subscribe("not-an-email", locale) for locale in ("ru", "en")]
        require(all(result.get("errors") for result in invalid), "Invalid email was accepted")
        errors = [result["errors"][0]["message"] for result in invalid]
        require(errors[0] != errors[1] and re.search("[А-Яа-я]", errors[0]), "Email validation is not localized")
        messages = [self.accepted(self.subscribe(self.emails[name], name)) for name in ("ru", "en")]
        require(messages[0] != messages[1], "Subscription messages are not localized")
        pending = self.emails["pending"]
        with ThreadPoolExecutor(max_workers=2) as workers:
            requests = [workers.submit(self.subscribe, email) for email in (pending, "  " + pending.upper() + "  ")]
            for request in requests:
                self.accepted(request.result())
        self.wait_messages(self.emails["pending"], 1)
        require(self.subscription(self.emails["pending"])["status"] == "pending", "Pending retry activated the address")
        expired_link = self.email_link(self.message(self.messages(self.emails["pending"])[0]["ID"]), "confirm")
        self.sql(
            "UPDATE delivery_targets SET email_confirmation_expires_at = CURRENT_TIMESTAMP - INTERVAL '1 minute' "
            "WHERE id = " + quote(self.subscription(self.emails["pending"])["id"])
        )
        for method in ("GET", "POST"):
            status, _ = self.request(expired_link, method=method, form={} if method == "POST" else None)
            require(status == 400, "An expired confirmation link was accepted")
        require(self.subscription(self.emails["pending"])["status"] == "pending", "Expired confirmation activated the address")
        ru_confirmation = self.confirm("ru", "ru")
        self.confirm("en", "en")
        self.accepted(self.subscribe(self.emails["ru"]))
        self.wait_messages(self.emails["ru"], 1)
        telegram_id = self.sql(
            "INSERT INTO delivery_targets (id, channel, destination, status, confirmed_at, locale) "
            "VALUES (gen_random_uuid(), 'telegram', '" + str(uuid.uuid4().int) + "', 'active', CURRENT_TIMESTAMP, 'ru') RETURNING id"
        )
        self.target_ids.add(telegram_id)
        print("PASS: RU/EN validation, double opt-in, safe GET and duplicate subscriptions")

        require(self.publish(first, admin=False).get("errors"), "Unauthenticated publication was allowed")
        for run_id in (str(uuid.uuid4()), self.fixture_run(status="queued"), self.fixture_run(complete=False)):
            require(self.publish(run_id).get("errors"), "A missing/incomplete run was published")
        failed_publication = None
        if self.failure_api:
            failed_publication = self.published(
                self.publish(first, api=self.failure_api), first, sent=0, failed=2,
            )
            failed_deliveries = self.rows(
                "SELECT d.status, d.attempts, d.last_error FROM deliveries d "
                "JOIN artifacts a ON a.id = d.artifact_id WHERE a.kind = 'email_preview' AND a.run_id = " + quote(first)
            )
            require(
                len(failed_deliveries) == 2 and all(
                    row["status"] == "failed" and row["attempts"] == 1
                    and row["last_error"] == "email_transport_failed"
                    for row in failed_deliveries
                ),
                "SMTP failures were not recorded with a generic error and one attempt",
            )
            require(self.digest()["runId"] == first, "SMTP failure rolled back publication")
            self.wait_messages(self.emails["ru"], 1)
            self.wait_messages(self.emails["en"], 1)
        published = self.published(self.publish(first), first, repeated=bool(self.failure_api))
        if failed_publication:
            require(published["publishedAt"] == failed_publication["publishedAt"], "Retry changed the original publication time")
        unsubscribe = self.newsletter("ru", 2, "ru")
        self.newsletter("en", 2, "en")
        self.wait_messages(self.emails["pending"], 1)
        deliveries = self.rows(
            "SELECT d.status, d.attempts, d.last_error, t.destination, t.channel FROM deliveries d "
            "JOIN delivery_targets t ON t.id = d.target_id "
            "JOIN artifacts a ON a.id = d.artifact_id WHERE a.kind = 'email_preview' AND a.run_id = " + quote(first)
        )
        require(len(deliveries) == 2 and all(row["status"] == "sent" and row["channel"] == "email" for row in deliveries), "Publication included an ineligible target")
        require(
            all(row["attempts"] == (2 if self.failure_api else 1) and row["last_error"] is None for row in deliveries),
            "Successful delivery did not preserve attempt counts and clear the error",
        )
        if self.failure_api:
            print("PASS: SMTP failure preserves publication and retries each failed delivery once")
        require(self.digest()["files"]["email"] == self.body, "Publication changed the saved artifact")
        print("PASS: admin publication delivers saved content only to confirmed email subscribers")

        self.accepted(self.subscribe(self.emails["late"], "en"))
        self.confirm("late", "en")
        repeated = self.published(self.publish(first), first, repeated=True)
        require(repeated["publishedAt"] == published["publishedAt"], "Repeated publication changed publishedAt")
        self.wait_messages(self.emails["ru"], 2)
        self.wait_messages(self.emails["en"], 2)
        self.wait_messages(self.emails["late"], 1)
        print("PASS: repeated publication sends no duplicates and keeps its recipient snapshot")

        status, page = self.request(unsubscribe)
        require(status == 200 and self.subscription(self.emails["ru"])["status"] == "active", "Unsubscribe GET changed subscription state")
        self.post_form(unsubscribe, page, "ru")
        require(self.subscription(self.emails["ru"])["status"] == "unsubscribed", "Unsubscribe POST did not stop subscription")
        self.request(ru_confirmation, method="POST", form={})
        require(self.subscription(self.emails["ru"])["status"] == "unsubscribed", "Reusing a confirmation link reactivated an unsubscribed address")
        second = self.fixture_run()
        self.published(self.publish(second), second)
        self.wait_messages(self.emails["ru"], 2)
        self.wait_messages(self.emails["pending"], 1)
        self.newsletter("en", 3, "en")
        self.newsletter("late", 2, "en")
        require(self.sql("SELECT COUNT(*) FROM model_calls") == "0", "Email publication unexpectedly called a model")
        print("PASS: POST unsubscribe excludes the address from the next issue; no model calls")

        last_allowed = self.emails["last_allowed"]
        self.accepted(self.subscribe(last_allowed))
        self.wait_messages(last_allowed, 1)
        require(self.subscription(last_allowed)["status"] == "pending", "Concurrent requests did not create one pending subscription")
        over_limit = self.emails["over_limit"]
        self.accepted(self.subscribe(over_limit))
        self.wait_messages(over_limit, 0)
        require(
            self.sql("SELECT COUNT(*) FROM delivery_targets WHERE destination = " + quote(over_limit)) == "0",
            "A rate-limited request created a pending row",
        )
        print("PASS: one email for concurrent requests and no database row above the quota")

        self.sql(
            "UPDATE email_subscription_limits SET window_start = CURRENT_TIMESTAMP - INTERVAL '10 minutes' "
            "WHERE ip_hash IN (" + ", ".join(map(quote, self.limit_ids)) + ")"
        )
        self.accepted(self.subscribe(self.emails["ru"]))
        self.confirm("ru", "ru", message_count=3)
        status, _ = self.request(unsubscribe, method="POST", form={})
        require(status == 400 and self.subscription(self.emails["ru"])["status"] == "active", "An old unsubscribe link still works")
        third = self.fixture_run()
        self.published(self.publish(third), third, sent=3)
        new_unsubscribe = self.newsletter("ru", 4, "ru")
        status, page = self.request(new_unsubscribe)
        require(status == 200, "The new unsubscribe link did not open")
        self.post_form(new_unsubscribe, page, "ru")
        require(self.subscription(self.emails["ru"])["status"] == "unsubscribed", "The new unsubscribe link did not work")
        print("PASS: re-confirmation rotates the unsubscribe token")

    def cleanup(self):
        failures = []
        try:
            captured = sorted({message["ID"] for message in self.messages()})
            if captured:
                status, _ = self.request(self.mailpit + "/api/v1/messages", method="DELETE", payload={"IDs": captured})
                require(status in (200, 204), "Could not remove captured fixture messages")
        except Exception:
            failures.append("Mailpit fixture messages")
        try:
            addresses = ", ".join(map(quote, self.emails.values()))
            self.target_ids.update(row["id"] for row in self.rows(
                "SELECT id FROM delivery_targets WHERE channel = 'email' AND destination IN (" + addresses + ")"
            ))
            statements = ["BEGIN"]
            if self.target_ids:
                targets = ", ".join(map(quote, self.target_ids))
                statements += [
                    "DELETE FROM deliveries WHERE target_id IN (" + targets + ")",
                    "DELETE FROM delivery_targets WHERE id IN (" + targets + ")",
                ]
            if self.run_ids:
                runs = ", ".join(map(quote, self.run_ids))
                artifacts = "SELECT id FROM artifacts WHERE run_id IN (" + runs + ")"
                statements += [
                    "DELETE FROM deliveries WHERE artifact_id IN (" + artifacts + ")",
                    "DELETE FROM email_preview_details WHERE artifact_id IN (" + artifacts + ")",
                    "DELETE FROM artifacts WHERE run_id IN (" + runs + ")",
                    "DELETE FROM runs WHERE id IN (" + runs + ")",
                ]
            if self.limit_ids:
                statements.append("DELETE FROM email_subscription_limits WHERE ip_hash IN (" + ", ".join(map(quote, self.limit_ids)) + ")")
            statements.append("COMMIT")
            self.sql(";\n".join(statements))
        except Exception:
            failures.append("PostgreSQL fixture rows")
        require(not failures, "Cleanup failed for: " + ", ".join(failures))


def main():
    if "--help" in sys.argv:
        print(__doc__)
        return 0
    smoke = NewsletterSmoke()
    failure = None
    try:
        smoke.run()
    except Exception as error:
        failure = error
    finally:
        # Do not touch services after an environment check rejected their URLs/DB.
        if smoke.run_ids:
            try:
                smoke.cleanup()
            except Exception as error:
                print("FAIL:", error, file=sys.stderr)
                if failure is None:
                    failure = error
    if failure is not None:
        print("FAIL:", failure, file=sys.stderr)
        return 1
    print("Email newsletter HTTP smoke passed; fixture rows and Mailpit messages removed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
