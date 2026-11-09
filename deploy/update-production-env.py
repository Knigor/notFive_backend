#!/usr/bin/env python3
"""Atomically install the complete production configuration received on stdin."""

import json
import os
from pathlib import Path
import re
import sys
import tempfile
from urllib.parse import urlsplit


CONFIG_KEYS = (
    "APP_SECRET JWT_SECRET ADMIN_BOOTSTRAP_USERNAME ADMIN_BOOTSTRAP_PASSWORD "
    "POSTGRES_PASSWORD POSTGRES_DB POSTGRES_USER APP_CORS_ORIGIN DEFAULT_URI HTTP_PORT "
    "MAILER_DSN MAIL_FROM_ADDRESS EMAIL_DELIVERY_MODE TELEGRAM_BOT_TOKEN DIGEST_SERVICE_TOKEN GO_SERVICE_URL "
    "YANDEX_API_KEY YANDEX_FOLDER_ID YANDEX_MODEL YANDEX_ANALYSIS_MODEL YANDEX_EXPORT_MODEL "
    "YANDEX_ANALYSIS_MAX_OUTPUT_TOKENS YANDEX_EXPORT_MAX_OUTPUT_TOKENS YANDEX_BASE_URL "
    "YANDEX_SEARCH_DOMAINS REQUEST_TIMEOUT MAX_CONCURRENT MAX_OUTPUT_TOKENS DIGEST_HTTP_PORT"
).split()
IMAGE_PATTERN = r"[a-z0-9][a-z0-9._/-]*(?::[a-zA-Z0-9_][a-zA-Z0-9_.-]*|@sha256:[a-f0-9]{64})"


def dotenv_values(text):
    """Read one-line values without executing shell code or expanding variables."""
    values = {}
    for line in text.splitlines():
        match = re.match(r"^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)$", line)
        if not match:
            continue
        key, value = match.groups()
        if value.startswith('"'):
            try:
                value, _ = json.JSONDecoder().raw_decode(value)
            except ValueError:
                raise ValueError("Cannot read existing configuration field " + key) from None
            value = value.replace("$$", "$")
        elif value.startswith("'"):
            if not value.endswith("'"):
                raise ValueError("Cannot read existing configuration field " + key)
            value = value[1:-1].replace("\\'", "'")
        else:
            value = re.split(r"\s+#", value, maxsplit=1)[0].strip()
        values[key] = value
    return values


def validate(config):
    if not isinstance(config, dict) or set(config) != set(CONFIG_KEYS):
        raise ValueError("Production configuration must contain exactly the documented fields")
    for key, value in config.items():
        if not isinstance(value, str) or not value or len(value) > 16384 or any(ord(c) < 32 or ord(c) == 127 for c in value):
            raise ValueError("Invalid production configuration field " + key)

    patterns = {
        "JWT_SECRET": r"[a-f0-9]{64}",
        "ADMIN_BOOTSTRAP_USERNAME": r"[a-z][a-z0-9_]{2,63}",
        "ADMIN_BOOTSTRAP_PASSWORD": r"[A-Za-z0-9_@.:-]{12,128}",
        "TELEGRAM_BOT_TOKEN": r"[0-9]+:[A-Za-z0-9_-]+",
    }
    for key, pattern in patterns.items():
        if not re.fullmatch(pattern, config[key]):
            raise ValueError("Invalid production configuration field " + key)
    if len(config["DIGEST_SERVICE_TOKEN"]) < 32:
        raise ValueError("Invalid production configuration field DIGEST_SERVICE_TOKEN")
    if config["GO_SERVICE_URL"] != "http://digest:8081":
        raise ValueError("GO_SERVICE_URL must address the internal digest service")
    if config["EMAIL_DELIVERY_MODE"] not in {"sync", "queued"}:
        raise ValueError("Invalid production configuration field EMAIL_DELIVERY_MODE")
    if config["MAILER_DSN"].startswith("null:"):
        raise ValueError("Production mail transport must be configured")
    for key, minimum, maximum in (
        ("HTTP_PORT", 1, 65535), ("DIGEST_HTTP_PORT", 1, 65535),
        ("MAX_CONCURRENT", 1, 8), ("MAX_OUTPUT_TOKENS", 100, 16000),
        ("YANDEX_ANALYSIS_MAX_OUTPUT_TOKENS", 100, 16000),
        ("YANDEX_EXPORT_MAX_OUTPUT_TOKENS", 100, 16000),
    ):
        if not config[key].isdigit() or not minimum <= int(config[key]) <= maximum:
            raise ValueError("Invalid production configuration field " + key)
    for key, schemes in (("DEFAULT_URI", {"http", "https"}), ("YANDEX_BASE_URL", {"https"})):
        url = urlsplit(config[key])
        if url.scheme not in schemes or not url.hostname or url.username or url.password:
            raise ValueError("Invalid production configuration field " + key)
    duration = re.fullmatch(r"([0-9]+)(s|m)", config["REQUEST_TIMEOUT"])
    if not duration or not 15 <= int(duration[1]) * (60 if duration[2] == "m" else 1) <= 600:
        raise ValueError("Invalid production configuration field REQUEST_TIMEOUT")
    domains = config["YANDEX_SEARCH_DOMAINS"].split(",")
    if not 1 <= len(domains) <= 5 or any(not re.fullmatch(r"[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?", domain.strip()) for domain in domains):
        raise ValueError("Invalid production configuration field YANDEX_SEARCH_DOMAINS")


def install(path, config, app_image, digest_image):
    validate(config)
    if not re.fullmatch(IMAGE_PATTERN, app_image) or not re.fullmatch(IMAGE_PATTERN, digest_image):
        raise ValueError("Invalid release image")
    original = path.read_text()
    existing = dotenv_values(original)
    # Changing Compose environment does not change roles in an existing PostgreSQL volume.
    for key in ("POSTGRES_DB", "POSTGRES_USER", "POSTGRES_PASSWORD"):
        previous = existing.get(key, "notfive" if key != "POSTGRES_PASSWORD" else "")
        if previous and previous != config[key]:
            raise ValueError("Existing database configuration differs for " + key)
    updates = dict(config, APP_IMAGE=app_image, DIGEST_IMAGE=digest_image)
    kept = []
    for line in original.splitlines():
        match = re.match(r"^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=", line)
        if not match or match[1] not in updates:
            kept.append(line)
    # Double-quoted dotenv values: JSON escaping protects quotes/backslashes;
    # $$ prevents Compose interpolation of dollar signs in passwords and tokens.
    kept.extend(key + "=" + json.dumps(value.replace("$", "$$"), ensure_ascii=False) for key, value in updates.items())
    fd, temporary = tempfile.mkstemp(prefix=path.name + ".", dir=path.parent)
    try:
        with os.fdopen(fd, "w") as stream:
            stream.write("\n".join(kept) + "\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def main():
    if len(sys.argv) == 3 and sys.argv[1] == "--uses-digest-network":
        values = dotenv_values(Path(sys.argv[2]).read_text())
        return 0 if values.get("GO_SERVICE_URL", "").rstrip("/") == "http://digest:8081" else 1
    if len(sys.argv) != 4:
        raise ValueError("Usage: update-production-env.py ENV_FILE APP_IMAGE DIGEST_IMAGE")
    install(Path(sys.argv[1]), json.load(sys.stdin), sys.argv[2], sys.argv[3])
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (ValueError, OSError):
        # Never print exception details: JSON/parser/OS messages can include secret values.
        print("Production configuration was rejected; check required fields and existing database settings.", file=sys.stderr)
        sys.exit(1)
