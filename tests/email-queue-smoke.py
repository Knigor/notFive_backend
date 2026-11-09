#!/usr/bin/env python3
"""Check queued email delivery through isolated HTTP, Doctrine Messenger and Mailpit.

Reuse the database, Mailpit, fixture and double opt-in helpers from
email-newsletter-smoke.py. Requirements: a migrated, initially empty *_smoke
PostgreSQL database, a queued-mode app, Mailpit, and no background email consumer.
No external SMTP or model is called. The worker uses the same APP_CONTAINER as
HTTP and inherits its Mailpit MAILER_DSN. DEFAULT_URI must point to API_ROOT.

Environment (plus credentials/database settings documented by the helper):
    API_QUEUE_ROOT            queued app origin; overrides API_ROOT when set
    API_ROOT                  http://127.0.0.1:8092
    APP_CONTAINER             required Docker app container using the isolated DB
    MAILPIT_URL               http://127.0.0.1:18025
    DATABASE_CONTAINER        notfive-dev-database-1
    DATABASE_USER             notfive
    TEST_DATABASE             notfive_email_smoke
    QUEUE_REQUEST_MAX_SECONDS  5; upper bound for subscribe/publish HTTP responses

Run sequentially: python3 -B tests/email-queue-smoke.py
SMTP failure is simulated only for one worker process with loopback port 1.
Captured output never prints credentials, tokens, message bodies or SMTP errors.
Cleanup removes only this run's fixtures, queue envelopes and Mailpit messages.
"""

import importlib.util
import math
import os
from pathlib import Path
import re
import subprocess
import sys
import time


spec = importlib.util.spec_from_file_location(
    "email_newsletter_smoke_helpers", Path(__file__).with_name("email-newsletter-smoke.py")
)
helper = importlib.util.module_from_spec(spec)
spec.loader.exec_module(helper)
NewsletterSmoke = helper.NewsletterSmoke
require = helper.require
quote = helper.quote


class EmailQueueSmoke(NewsletterSmoke):
    def __init__(self):
        super().__init__()
        self.api = os.getenv("API_QUEUE_ROOT", self.api).rstrip("/")
        self.failure_api = ""
        self.app_container = os.getenv("APP_CONTAINER", "")
        self.max_response_seconds = float(os.getenv("QUEUE_REQUEST_MAX_SECONDS", "5"))

    def validate_environment(self):
        require(
            re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]*", self.app_container),
            "APP_CONTAINER must name the isolated queued-mode app container",
        )
        require(
            math.isfinite(self.max_response_seconds) and self.max_response_seconds > 0,
            "QUEUE_REQUEST_MAX_SECONDS must be a positive finite number",
        )
        super().validate_environment()
        probe = subprocess.run(
            [
                "docker", "exec", self.app_container, "php", "-r",
                "$dsn = parse_url((string) getenv('MAILER_DSN')); "
                "exit(is_array($dsn) && ($dsn['scheme'] ?? '') === 'smtp' "
                "&& ($dsn['host'] ?? '') === 'mailpit' && ($dsn['port'] ?? 0) === 1025 "
                "&& !isset($dsn['user']) && !isset($dsn['pass']) "
                "&& getenv('EMAIL_DELIVERY_MODE') === 'queued' ? 0 : 1);",
            ],
            capture_output=True, text=True, timeout=10, check=False,
        )
        require(
            probe.returncode == 0,
            "APP_CONTAINER must use EMAIL_DELIVERY_MODE=queued and isolated smtp://mailpit:1025",
        )
        require(not self.queue_rows(), "The isolated email/email_failed queues must initially be empty")

    def console(self, arguments, *, fail_smtp=False):
        command = ["docker", "exec"]
        if fail_smtp:
            command += ["-e", "MAILER_DSN=smtp://127.0.0.1:1?timeout=1"]
        command += [self.app_container, "php", "bin/console", *arguments, "--no-interaction"]
        try:
            result = subprocess.run(command, capture_output=True, text=True, timeout=25, check=False)
        except subprocess.TimeoutExpired:
            raise AssertionError("The isolated console/worker command exceeded its deadline") from None
        require(result.returncode == 0, "An isolated console/worker command failed; inspect the smoke container locally")
        return result.stdout

    def queue_rows(self, queue=None):
        predicate = "queue_name IN ('email', 'email_failed')"
        if queue:
            require(queue in ("email", "email_failed"), "Unexpected queue name")
            predicate = "queue_name = " + quote(queue)
        return self.rows(
            "SELECT id, queue_name FROM messenger_messages WHERE " + predicate + " ORDER BY id"
        )

    def expect_queue(self, count, *, failed=0):
        require(len(self.queue_rows("email")) == count, "Unexpected pending email queue size")
        require(len(self.queue_rows("email_failed")) == failed, "Unexpected failed email queue size")

    def duplicate_envelope(self, message_id):
        duplicate_id = self.sql(
            "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at, delivered_at) "
            "SELECT body, headers, queue_name, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, NULL "
            "FROM messenger_messages WHERE id = " + quote(message_id) + " RETURNING id"
        )
        require(duplicate_id.isdecimal(), "Could not duplicate the isolated test envelope")

    def consume(self, count=1, *, fail_smtp=False):
        for _ in range(count):
            self.console(
                ["messenger:consume", "email", "--limit=1", "--time-limit=5", "--sleep=0.1", "--memory-limit=128M"],
                fail_smtp=fail_smtp,
            )

    def quick(self, action, label):
        started = time.monotonic()
        value = action()
        require(
            time.monotonic() - started < self.max_response_seconds,
            label + " did not return within the configured queued-response deadline",
        )
        return value

    def queued_publication(self, run_id, *, pending, sent=0, failed=0, repeated=False):
        result = self.quick(lambda: self.publish(run_id), "publishDigest")
        require(not result.get("errors"), "Queued publishDigest failed")
        value = result["data"]["publishDigest"]
        require(value["runId"] == run_id and value["publishedAt"], "Missing publication metadata")
        require(value["alreadyPublished"] is repeated, "Unexpected publication idempotency flag")
        require(
            (value["emailSent"], value["emailFailed"], value["emailPending"]) == (sent, failed, pending),
            "Publication reported delivery before the consumer or returned incorrect counters",
        )
        return value

    def deliveries(self, run_id):
        return self.rows(
            "SELECT d.id, d.status, d.attempts, d.last_error, t.destination FROM deliveries d "
            "JOIN delivery_targets t ON t.id = d.target_id "
            "JOIN artifacts a ON a.id = d.artifact_id "
            "WHERE a.kind = 'email_preview' AND a.run_id = " + quote(run_id)
            + " ORDER BY t.destination"
        )

    def run(self):
        self.validate_environment()
        self.authenticate()
        first = self.confirm_database_binding()
        binding = self.console([
            "dbal:run-sql", "SELECT id FROM runs WHERE id = " + quote(first),
        ])
        require(first in binding, "APP_CONTAINER is not bound to the isolated fixture database")
        print("PASS: queued HTTP app and CLI consumer use the isolated fixture database")

        result = self.quick(lambda: self.subscribe(self.emails["ru"]), "subscribeEmail")
        self.accepted(result)
        require(self.subscription(self.emails["ru"])["status"] == "pending", "Subscription activated before confirmation")
        self.expect_queue(1)
        require(not self.messages(), "HTTP subscription sent SMTP before the consumer")
        self.duplicate_envelope(self.queue_rows("email")[0]["id"])
        self.consume()
        self.expect_queue(1)
        self.wait_messages(self.emails["ru"], 1)
        self.consume()
        self.expect_queue(0)
        self.wait_messages(self.emails["ru"], 1)
        self.confirm("ru", "ru")
        print("PASS: subscribe returns before SMTP; consumer sends one confirmation despite duplicate envelopes")

        self.accepted(self.quick(lambda: self.subscribe(self.emails["en"], "en"), "subscribeEmail"))
        self.expect_queue(1)
        require(not self.messages(self.emails["en"]), "English confirmation bypassed the queue")
        self.consume()
        self.expect_queue(0)
        self.confirm("en", "en")
        print("PASS: queued confirmations preserve RU/EN double opt-in")

        published = self.queued_publication(first, pending=2)
        self.expect_queue(2)
        initial = self.deliveries(first)
        require(
            len(initial) == 2 and all(row["status"] == "queued" and row["attempts"] == 0 for row in initial),
            "Publication attempted SMTP or marked deliveries sent before consumption",
        )
        self.wait_messages(self.emails["ru"], 1)
        self.wait_messages(self.emails["en"], 1)
        self.duplicate_envelope(self.queue_rows("email")[0]["id"])
        self.consume(2)
        unsubscribe = self.newsletter("ru", 2, "ru")
        self.newsletter("en", 2, "en")
        self.consume()
        self.expect_queue(0)
        self.wait_messages(self.emails["ru"], 2)
        self.wait_messages(self.emails["en"], 2)
        delivered = self.deliveries(first)
        require(
            len(delivered) == 2 and all(
                row["status"] == "sent" and row["attempts"] == 1 and row["last_error"] is None
                for row in delivered
            ),
            "Duplicate delivery envelope caused another SMTP attempt",
        )
        require(self.digest()["files"]["email"] == self.body, "Queued delivery changed the saved artifact")
        repeated = self.queued_publication(first, pending=0, sent=2, repeated=True)
        require(repeated["publishedAt"] == published["publishedAt"], "Repeated publication changed publication time")
        self.expect_queue(0)
        print("PASS: publication queues saved content; consumer and repeat publication do not duplicate delivery")

        second = self.fixture_run()
        self.queued_publication(second, pending=2)
        self.expect_queue(2)
        status, page = self.request(unsubscribe)
        require(status == 200 and self.subscription(self.emails["ru"])["status"] == "active", "Unsubscribe GET changed subscription state")
        self.post_form(unsubscribe, page, "ru")
        require(self.subscription(self.emails["ru"])["status"] == "unsubscribed", "Unsubscribe POST failed")
        self.consume(2)
        self.expect_queue(0)
        self.wait_messages(self.emails["ru"], 2)
        self.newsletter("en", 3, "en")
        statuses = {row["destination"]: row for row in self.deliveries(second)}
        require(
            statuses[self.emails["ru"]]["status"] == "cancelled"
            and statuses[self.emails["ru"]]["attempts"] == 0
            and statuses[self.emails["en"]]["status"] == "sent",
            "Consumer sent a queued newsletter after unsubscribe",
        )
        print("PASS: unsubscribe cancels queued delivery before the consumer without SMTP")

        third = self.fixture_run()
        self.queued_publication(third, pending=1)
        self.expect_queue(1)
        self.consume(fail_smtp=True)
        failed = self.deliveries(third)
        require(
            len(failed) == 1 and failed[0]["status"] == "failed"
            and failed[0]["attempts"] == 1 and failed[0]["last_error"] == "email_transport_failed",
            "Worker SMTP failure did not preserve a retryable delivery with a generic error",
        )
        require(
            self.sql("SELECT published_at IS NOT NULL FROM runs WHERE id = " + quote(third)) == "t",
            "SMTP failure rolled back publication",
        )
        self.wait_messages(self.emails["en"], 3)
        self.expect_queue(1)
        retry_id = self.queue_rows("email")[0]["id"]
        self.sql(
            "UPDATE messenger_messages SET available_at = CURRENT_TIMESTAMP - INTERVAL '1 second' "
            "WHERE id = " + quote(retry_id)
        )
        self.consume()
        self.expect_queue(0)
        self.newsletter("en", 4, "en")
        retried = self.deliveries(third)
        require(
            len(retried) == 1 and retried[0]["status"] == "sent"
            and retried[0]["attempts"] == 2 and retried[0]["last_error"] is None,
            "Healthy worker did not retry the failed delivery exactly once",
        )
        self.queued_publication(third, pending=0, sent=1, repeated=True)
        self.expect_queue(0)
        self.wait_messages(self.emails["en"], 4)
        require(self.sql("SELECT COUNT(*) FROM model_calls") == "0", "Email queue unexpectedly called a model")
        print("PASS: SMTP failure is recorded and retried by Messenger without duplicate delivery or model calls")

    def cleanup(self):
        failure = None
        try:
            addresses = ", ".join(map(quote, self.emails.values()))
            ids = {row["id"] for row in self.rows(
                "SELECT id FROM delivery_targets WHERE channel = 'email' AND destination IN (" + addresses + ")"
            )}
            if self.run_ids:
                ids.update(row["id"] for row in self.rows(
                    "SELECT d.id FROM deliveries d JOIN artifacts a ON a.id = d.artifact_id "
                    "WHERE a.run_id IN (" + ", ".join(map(quote, self.run_ids)) + ")"
                ))
            if ids:
                predicate = " OR ".join("body LIKE " + quote("%" + value + "%") for value in ids)
                self.sql(
                    "DELETE FROM messenger_messages WHERE queue_name IN ('email', 'email_failed') "
                    "AND (" + predicate + ")"
                )
        except Exception:
            failure = "Could not remove this run's email queue fixture envelopes"
        try:
            super().cleanup()
        finally:
            require(failure is None, failure)


def main():
    if "--help" in sys.argv:
        print(__doc__)
        return 0
    smoke = EmailQueueSmoke()
    failure = None
    try:
        smoke.run()
    except Exception as error:
        failure = error
    finally:
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
    print("Email queue HTTP smoke passed; fixture rows, envelopes and Mailpit messages removed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
