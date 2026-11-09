#!/usr/bin/env python3
"""Check subscribeEmail against a local SMTP server that never sends a greeting.

Uses the isolated DB/Mailpit setup from email-newsletter-smoke.py.
API_FAILURE_ROOT must point to an app on the same *_smoke database whose SMTP
server accepts TCP connections but remains silent. No external SMTP is used.
"""

import runpy
import sys
import time
from pathlib import Path

helpers = runpy.run_path(str(Path(__file__).with_name("email-newsletter-smoke.py")))
NewsletterSmoke = helpers["NewsletterSmoke"]
require = helpers["require"]
quote = helpers["quote"]


def main():
    smoke = NewsletterSmoke()
    try:
        require(smoke.failure_api, "API_FAILURE_ROOT must use a silent local SMTP server")
        smoke.validate_environment()
        smoke.authenticate()
        smoke.confirm_database_binding()
        errors = []
        for locale in ("ru", "en"):
            email = smoke.emails[locale]
            started = time.monotonic()
            result = smoke.subscribe(email, locale, api=smoke.failure_api)
            elapsed = time.monotonic() - started
            require(4 <= elapsed < 12, "SMTP wait was not bounded near the configured 5 seconds")
            require(result.get("errors"), "SMTP timeout must not return accepted")
            message = result["errors"][0]["message"]
            require(message and email not in message and "smtp://" not in message, "SMTP details leaked into the response")
            errors.append(message)
            target = smoke.subscription(email)
            require(target["status"] == "pending", "Timeout activated an unconfirmed address")
            cleared = smoke.sql(
                "SELECT COUNT(*) FROM delivery_targets WHERE id = " + quote(target["id"])
                + " AND email_confirmation_token_hash IS NULL"
                + " AND email_confirmation_expires_at IS NULL"
                + " AND email_confirmation_sent_at IS NULL"
            )
            require(cleared == "1", "SMTP failure left a stale confirmation reservation")
            smoke.accepted(smoke.subscribe(email, locale))
            smoke.wait_messages(email, 1)
            print(f"PASS: {locale} SMTP timeout after {elapsed:.1f}s; JSON error, reservation cleared, Mailpit retry succeeded")
        require(errors[0] != errors[1], "SMTP errors must be translated for RU and EN")
    finally:
        if smoke.run_ids:
            smoke.cleanup()
    print("SMTP timeout HTTP smoke passed; fixture rows and captured mail removed.")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print("FAIL:", error, file=sys.stderr)
        sys.exit(1)
