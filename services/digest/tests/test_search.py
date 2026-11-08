"""Tests use a local HTTP stub only; no real service or model calls."""

import contextlib
import copy
from datetime import datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
import threading
import unittest
from unittest import mock


SCRIPT = Path(__file__).resolve().parents[1] / "scripts" / "search.py"
spec = importlib.util.spec_from_file_location("search_cli", SCRIPT)
search_cli = importlib.util.module_from_spec(spec)
spec.loader.exec_module(search_cli)
TOKEN = "only-a-local-test-token-with-32-plus-characters"
PAYLOAD = {"control_time": "2026-11-08T13:01:59+03:00", "query": "Свежие новости ИБ"}
RESULT = {
    "dataset": "web",
    "control_time": PAYLOAD["control_time"],
    "period_start": "2026-11-07T13:01:59+03:00",
    "timezone": "Europe/Moscow",
    "description": "Публикации из открытых источников.",
    "fields": {
        "id": "ID материала", "title": "заголовок", "text": "текст",
        "source": "кто опубликовал", "url": "ссылка на публикацию",
        "published_at": "дата публикации", "missing": "незаполненные поля",
        "error": "ошибка получения материала",
    },
    "items": [{
        "id": "web-test",
        "url": "https://example.org/news/test",
        "title": "Новость об ИБ",
        "text": "Полный текст статьи для передачи в Symfony.",
        "source": "example.org",
        "published_at": "2026-11-08T10:00:00+03:00",
        "missing": [],
        "error": None,
    }],
}


@contextlib.contextmanager
def fake_server(status=201, body=None, location=None):
    calls = []

    class Handler(BaseHTTPRequestHandler):
        def do_POST(self):
            calls.append({"authorization": self.headers.get("Authorization"), "path": self.path, "body": json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))))})
            data = json.dumps(RESULT if body is None else body).encode()
            self.send_response(status)
            if location is not None:
                self.send_header("Location", location)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)

        def do_GET(self):
            calls.append({"authorization": self.headers.get("Authorization"), "path": self.path})
            self.send_response(200)
            self.end_headers()

        def log_message(self, *args):
            pass

    server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield "http://127.0.0.1:" + str(server.server_port), calls
    finally:
        server.shutdown()
        server.server_close()
        thread.join()


class SearchCLITest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.input = self.root / "search.json"
        self.input.write_text(json.dumps(PAYLOAD), encoding="utf-8")

    def cli(self, *args):
        env = os.environ.copy()
        env["DIGEST_SERVICE_TOKEN"] = TOKEN
        return subprocess.run([sys.executable, str(SCRIPT), str(self.input), *args], env=env, capture_output=True, text=True, timeout=10)

    def test_literal_dotenv_and_environment_precedence(self):
        dotenv = self.root / ".env"
        marker = self.root / "must-not-exist"
        dotenv.write_text(f"YANDEX_API_KEY=$(touch {marker})\nexport DIGEST_SERVICE_TOKEN='{TOKEN}' # local token\n", encoding="utf-8")
        with mock.patch.dict(os.environ, {}, clear=True):
            self.assertEqual(search_cli.service_token(dotenv), TOKEN)
        self.assertFalse(marker.exists())
        dotenv.write_text("DIGEST_SERVICE_TOKEN='unterminated\n", encoding="utf-8")
        with mock.patch.dict(os.environ, {"DIGEST_SERVICE_TOKEN": TOKEN}, clear=True):
            self.assertEqual(search_cli.service_token(dotenv), TOKEN)

    def test_fixed_time_now_and_private_output(self):
        original = self.input.read_bytes()
        output = self.root / "result.json"
        with fake_server() as (url, calls):
            result = self.cli("--url", url, "--output", str(output))
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout), RESULT)
            self.assertIn("Новость об ИБ", result.stdout)
            self.assertNotIn("research", json.loads(result.stdout))
            self.assertNotIn("result", RESULT)
            self.assertNotIn("collection", json.loads(result.stdout))
            self.assertEqual(calls[0]["body"], PAYLOAD)
            self.assertEqual(calls[0]["authorization"], "Bearer " + TOKEN)
            self.assertEqual(calls[0]["path"], "/v1/collect")
            self.assertEqual(json.loads(output.read_text()), RESULT)
            self.assertEqual(stat.S_IMODE(output.stat().st_mode), 0o600)
            now_result = self.cli("--url", url, "--now")
            self.assertEqual(now_result.returncode, 0, now_result.stderr)
            observed = datetime.fromisoformat(calls[1]["body"]["control_time"])
            self.assertEqual(observed.utcoffset(), timedelta(hours=3))
            self.assertLess(abs((datetime.now(observed.tzinfo) - observed).total_seconds()), 10)
        self.assertEqual(self.input.read_bytes(), original)
        self.assertNotIn(TOKEN, result.stdout + result.stderr)

    def test_empty_news_is_successful_without_a_digest(self):
        empty = copy.deepcopy(RESULT)
        empty["items"] = []
        with fake_server(body=empty) as (url, calls):
            result = self.cli("--url", url)
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual(json.loads(result.stdout), empty)
            self.assertEqual(len(calls), 1)

    def test_wrong_news_format_is_reported(self):
        invalid_items = {**RESULT, "items": None}
        invalid_metadata = {**RESULT, "fields": None}
        old_format = {"id": "1" * 32, "mode": "collection", "status": "completed", "collection": {"articles": []}}
        for invalid in (invalid_items, invalid_metadata, old_format):
            with self.subTest(body=invalid), fake_server(body=invalid) as (url, calls):
                result = self.cli("--url", url)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("news.json", result.stderr)
                self.assertEqual(result.stdout, "")
                self.assertEqual(len(calls), 1)

    def test_redirect_does_not_forward_bearer(self):
        with fake_server() as (other_url, other_calls):
            with fake_server(status=302, location=other_url + "/capture") as (url, calls):
                result = self.cli("--url", url)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("перенаправление", result.stderr)
                self.assertEqual(len(calls), 1)
                self.assertEqual(other_calls, [])
                self.assertNotIn(TOKEN, result.stderr)

    def test_check_and_bad_output_do_not_send_request(self):
        with fake_server() as (url, calls):
            result = self.cli("--url", url, "--check")
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("не отправлялся", result.stdout)
            result = self.cli("--url", url, "--output", str(self.root / "missing/result.json"))
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(calls, [])

    def test_http_error_does_not_print_token(self):
        with fake_server(status=502, body={"message": "Test error " + TOKEN}) as (url, calls):
            result = self.cli("--url", url)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("HTTP 502", result.stderr)
            self.assertNotIn(TOKEN, result.stderr)
            self.assertEqual(len(calls), 1)


if __name__ == "__main__":
    unittest.main()
