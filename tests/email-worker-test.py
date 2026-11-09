#!/usr/bin/env python3
"""Safety tests for the local launcher. No network, Docker or real mail calls."""

import argparse
import importlib.util
import io
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("email_worker", ROOT / "scripts/email-worker.py")
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


def fixture():
    return {
        "image": "ghcr.io/knigor/notfive_backend@sha256:" + "a" * 64,
        "database_ip": "172.19.0.2", "mode": "queued",
        "environment": {
            "MAILER_DSN": "smtp://dummy.invalid:1025", "MAIL_FROM_ADDRESS": "test@example.invalid",
            "APP_SECRET": "value with $dollar and 'quote\"", "POSTGRES_DB": "notfive_smoke",
            "POSTGRES_USER": "dummy", "POSTGRES_PASSWORD": "=fake-$password-not-real",
            "DEFAULT_URI": "https://example.invalid",
        },
    }


class WorkerTests(unittest.TestCase):
    def test_untrusted_host_and_port_rejected(self):
        for flag, value in [("--host", "host;echo-bad"), ("--host", "-oProxyCommand=bad"), ("--user", "root@other"), ("--local-port", "0")]:
            with self.subTest(flag=flag, value=value):
                args = worker.parser().parse_args(["--status", flag + "=" + value])
                with self.assertRaises(worker.WorkerError):
                    worker.validate_args(args)

    def test_metadata_requires_pinned_production_image_and_queue_mode(self):
        worker.validate_metadata(fixture())
        for key, value in [("image", "attacker/image:latest"), ("image", "ghcr.io/knigor/notfive_backend:latest"), ("database_ip", "8.8.8.8"), ("mode", "sync")]:
            data = fixture()
            data[key] = value
            with self.subTest(key=key), self.assertRaises(worker.WorkerError):
                worker.validate_metadata(data)

    def test_multiline_secret_rejected_without_disclosure(self):
        data = fixture()
        data["environment"]["APP_SECRET"] = "SECRET_BAIT\nINJECTED=bad"
        with self.assertRaises(worker.WorkerError) as caught:
            worker.validate_metadata(data)
        self.assertNotIn("SECRET_BAIT", str(caught.exception))

    def test_environment_is_literal_private_file_not_command_argument(self):
        with tempfile.TemporaryDirectory() as directory:
            args = argparse.Namespace(state_dir=Path(directory), local_port=15433)
            data = fixture()
            path = worker.environment_file(data, args)
            try:
                self.assertEqual(0o600, path.stat().st_mode & 0o777)
                actual = dict(line.split("=", 1) for line in path.read_text().splitlines())
                for key, value in data["environment"].items():
                    self.assertEqual(value, actual[key])
                supervisor = worker.Supervisor(args)
                supervisor.image = data["image"]
                supervisor.env_path = path
                command = supervisor.docker_command(["bin/console", "messenger:consume", "email"])
                self.assertIn("--no-healthcheck", command)
                for key in ("APP_SECRET", "POSTGRES_PASSWORD"):
                    self.assertNotIn(data["environment"][key], " ".join(command))
                supervisor.close()
                self.assertFalse(path.exists())
            finally:
                path.unlink(missing_ok=True)

    def test_foreign_container_is_never_stopped(self):
        supervisor = worker.Supervisor(argparse.Namespace())
        supervisor.owns_container = True
        result = subprocess.CompletedProcess([], 0, stdout="another-session\n")
        with patch.object(supervisor, "command", return_value=result) as command:
            supervisor.stop_worker()
        self.assertEqual(1, command.call_count)
        self.assertEqual("inspect", command.call_args.args[0][1])

    def test_owned_container_is_stopped(self):
        supervisor = worker.Supervisor(argparse.Namespace())
        supervisor.owns_container = True
        result = subprocess.CompletedProcess([], 0, stdout=supervisor.session + "\n")
        with patch.object(supervisor, "command", return_value=result) as command:
            supervisor.stop_worker()
        self.assertEqual("stop", command.call_args_list[-1].args[0][1])

    def test_check_output_omits_unsolicited_secrets(self):
        supervisor = worker.Supervisor(argparse.Namespace())
        result = subprocess.CompletedProcess([], 0, stdout="SECRET_BAIT\nCHECK database_ok queued=2 failed=0\nCHECK smtp_authenticated_no_email_sent\n")
        with patch.object(supervisor, "prepare"), patch.object(supervisor, "docker_command", return_value=[]), patch.object(supervisor, "command", return_value=result), patch("sys.stdout", new_callable=io.StringIO) as output:
            self.assertEqual(0, supervisor.check())
            self.assertNotIn("SECRET_BAIT", output.getvalue())
            self.assertIn("queued=2", output.getvalue())

    def test_metadata_capture_does_not_log_remote_failure_output(self):
        args = argparse.Namespace(identity=Path("/dummy/key"), port=22, user="root", host="example.invalid")
        with patch.object(worker.subprocess, "run", side_effect=subprocess.CalledProcessError(1, [], output="SECRET_BAIT")):
            with self.assertRaises(worker.WorkerError) as caught:
                worker.metadata(args)
        self.assertNotIn("SECRET_BAIT", str(caught.exception))


if __name__ == "__main__":
    unittest.main()
