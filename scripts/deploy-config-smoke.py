#!/usr/bin/env python3
"""Exercise production configuration and rollback using dummy data only."""

import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile


ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("production_env", ROOT / "deploy/update-production-env.py")
updater = importlib.util.module_from_spec(spec)
spec.loader.exec_module(updater)
APP = "ghcr.io/example/app@sha256:" + "a" * 64
GO = "ghcr.io/example/digest@sha256:" + "b" * 64
OLD_APP = "ghcr.io/example/app:old"
OLD_GO = "ghcr.io/example/digest:old"


def config():
    values = {key: "smoke-only" for key in updater.CONFIG_KEYS}
    values.update(
        APP_SECRET="space $VAR ${VAR} $$ 'double\" back\\slash trailing\\",
        JWT_SECRET="c" * 64, ADMIN_BOOTSTRAP_USERNAME="smoke_admin",
        ADMIN_BOOTSTRAP_PASSWORD="smoke-password-123", POSTGRES_DB="notfive",
        POSTGRES_USER="notfive", POSTGRES_PASSWORD="smoke-db-password",
        HTTP_PORT="8080", DIGEST_HTTP_PORT="8081", MAILER_DSN="smtp://smoke.invalid:25",
        MAIL_FROM_ADDRESS="smoke@example.invalid", TELEGRAM_BOT_TOKEN="123:smoke_only",
        DIGEST_SERVICE_TOKEN="d" * 64, GO_SERVICE_URL="http://digest:8081",
        DEFAULT_URI="https://example.invalid", APP_CORS_ORIGIN="https://frontend.example.invalid",
        YANDEX_BASE_URL="https://ai.api.cloud.yandex.net/v1", REQUEST_TIMEOUT="180s",
        MAX_CONCURRENT="2", MAX_OUTPUT_TOKENS="8000", YANDEX_ANALYSIS_MAX_OUTPUT_TOKENS="12000",
        YANDEX_EXPORT_MAX_OUTPUT_TOKENS="8000", YANDEX_SEARCH_DOMAINS="example.invalid,news.example.invalid",
    )
    return values


def check_updater(directory):
    path = directory / ".env.production"
    original = "# Keep comments\nUNRELATED=keep\nPOSTGRES_PASSWORD=smoke-db-password\n"
    path.write_text(original)
    values = config()
    updater.install(path, values, APP, GO)
    assert path.stat().st_mode & 0o777 == 0o600
    saved = path.read_text()
    assert updater.dotenv_values(saved) == dict(values, APP_IMAGE=APP, DIGEST_IMAGE=GO, UNRELATED="keep")
    updater.install(path, values, APP, GO)
    assert path.read_text() == saved
    for bad in (dict(values, UNKNOWN="no"), dict(values, YANDEX_API_KEY="secret\nnewline"), dict(values, POSTGRES_PASSWORD="different"), dict(values, GO_SERVICE_URL="http://other:8081")):
        try:
            updater.install(path, bad, APP, GO)
        except ValueError:
            pass
        else:
            raise AssertionError("Invalid configuration accepted")
        assert path.read_text() == saved
    result = subprocess.run([sys.executable, str(ROOT / "deploy/update-production-env.py"), str(path), APP, GO], input='{"secret-bait":', text=True, capture_output=True)
    assert result.returncode != 0 and "secret-bait" not in result.stderr
    assert path.read_text() == saved
    if shutil.which("docker"):
        compose = directory / "compose.yaml"
        compose.write_text("services:\n  app:\n    image: alpine\n    environment:\n      APP_SECRET: ${APP_SECRET}\n")
        clean_env = {key: value for key, value in os.environ.items() if key not in values}
        result = subprocess.run(["docker", "compose", "--env-file", str(path), "-f", str(compose), "config", "--environment"], env=clean_env, capture_output=True, text=True, check=True)
        actual = dict(line.split("=", 1) for line in result.stdout.splitlines() if "=" in line)
        assert all(actual[key] == value for key, value in values.items()), "Compose changed a literal configuration value"
        print("PASS: Docker Compose preserves literal dollars, quotes, spaces and backslashes")
    else:
        print("SKIP: Docker Compose literal roundtrip (Docker unavailable)")


MOCK_DOCKER = r'''#!/usr/bin/env python3
import json, os, pathlib, sys
path = pathlib.Path(os.environ["MOCK_STATE"])
state = json.loads(path.read_text())
args = sys.argv[1:]
output = ""
code = 0
stage = ""
if args[0] == "ps":
    filters = [value for value in args if value.startswith("label=")]
    project = next(value.split("=", 2)[2] for value in filters if "project=" in value)
    service = next(value.split("=", 2)[2] for value in filters if "service=" in value)
    key = project + "/" + service
    if key in state["containers"]: output = key
elif args[0] == "inspect":
    container = state["containers"][args[-1]]
    output = container["image"] if "Config.Image" in args[2] else str(container["running"]).lower()
elif args[0] == "stop":
    stage = "stop:" + args[1]
    state["containers"][args[1]]["running"] = False
elif args[0] == "compose":
    go = any(value.endswith("services/digest/compose.prod.yaml") for value in args)
    project = "notfive-digest" if go else "notfive"
    op = next(value for value in args if value in ("config", "pull", "up", "run", "exec"))
    services = [value for value in args[args.index(op) + 1:] if value in ("app", "telegram", "digest_worker", "digest", "database")]
    stage = op + ":" + (services[-1] if services else "config")
    if op == "exec": stage = "exec:migrate" if "app-migrate" in args else "exec:health"
    if op == "run": stage = "run:profile" if "telegram:configure-profile" in args else "run:poll"
    if state.get("fail") == stage and not state.get("failed"):
        state["failed"] = True
        code = 42
    elif op == "up":
        for service in services:
            if service != "database":
                image = os.environ["DIGEST_IMAGE" if go else "APP_IMAGE"]
                state["containers"][project + "/" + service] = {"image": image, "running": "--no-start" not in args}
else: code = 90
if stage: state["log"].append(stage)
path.write_text(json.dumps(state))
if output: print(output)
sys.exit(code)
'''


def check_deploy(parent, fail, existing_go=False, previously_stopped=False):
    directory = parent / ((fail or "success").replace(":", "-") + ("-existing" if existing_go else "") + ("-stopped" if previously_stopped else ""))
    directory.mkdir()
    (directory / "deploy").mkdir()
    binary = directory / "bin"
    binary.mkdir()
    (binary / "docker").write_text(MOCK_DOCKER)
    (binary / "docker").chmod(0o755)
    (binary / "flock").write_text("#!/bin/sh\nexit 0\n")
    (binary / "flock").chmod(0o755)
    shutil.copy(ROOT / "deploy/update-production-env.py", directory / "deploy")
    source = (ROOT / "deploy/deploy.sh").read_text().replace("cd /opt/notfive\n", "cd '" + str(directory) + "'\n").replace("--env-file /opt/notfive/.env.production", "--env-file '" + str(directory / ".env.production") + "'")
    script = directory / "deploy/deploy.sh"
    script.write_text(source)
    values = config()
    env_path = directory / ".env.production"
    env_path.write_text("POSTGRES_PASSWORD=smoke-db-password\n")
    updater.install(env_path, values, OLD_APP, OLD_GO)
    if not existing_go:
        env_path.write_text("\n".join(line for line in env_path.read_text().splitlines() if not line.startswith(("YANDEX_", "DIGEST_IMAGE=", "GO_SERVICE_URL="))) + "\nGO_SERVICE_URL=\n")
    original = env_path.read_bytes()
    initial = {"notfive/app": {"image": OLD_APP, "running": True}, "notfive/telegram": {"image": OLD_APP, "running": True}}
    if existing_go:
        initial.update({"notfive-digest/digest": {"image": OLD_GO, "running": True}, "notfive/digest_worker": {"image": OLD_APP, "running": True}})
    if previously_stopped:
        for container in initial.values():
            container["running"] = False
    state_path = directory / "state.json"
    state_path.write_text(json.dumps({"containers": initial, "fail": fail, "log": []}))
    environment = dict(os.environ, PATH=str(binary) + os.pathsep + os.environ["PATH"], MOCK_STATE=str(state_path))
    result = subprocess.run(["sh", str(script), APP, "--config-from-stdin", GO], input=json.dumps(values), capture_output=True, text=True, env=environment)
    state = json.loads(state_path.read_text())
    if fail:
        assert result.returncode != 0, fail
        assert env_path.read_bytes() == original, "Environment was not restored after " + fail
        assert all(state["containers"][key] == value for key, value in initial.items()), "Previous containers were not restored after " + fail
        assert all(not value["running"] for key, value in state["containers"].items() if key not in initial), "New container remained running after " + fail
    else:
        assert result.returncode == 0, result.stderr
        assert state["containers"]["notfive/app"]["image"] == APP
        assert state["containers"]["notfive-digest/digest"]["image"] == GO
        assert state["containers"]["notfive/digest_worker"]["running"]
        log = state["log"]
        assert log.index("pull:app") < log.index("stop:notfive/telegram")
        assert log.index("pull:digest") < log.index("stop:notfive/telegram")
        assert log.index("up:digest") < log.index("up:app") < log.index("up:digest_worker")
    assert values["APP_SECRET"] not in result.stdout + result.stderr
    assert not list(directory.glob(".env.production.backup.*"))


def main():
    with tempfile.TemporaryDirectory(prefix="notfive-deploy-smoke-") as temporary:
        directory = Path(temporary)
        check_updater(directory)
        check_deploy(directory, None)
        stages = ("pull:app", "pull:digest", "up:digest", "up:app", "exec:migrate", "exec:health", "run:poll", "run:profile", "up:telegram", "up:digest_worker")
        for stage in stages:
            check_deploy(directory, stage)
        check_deploy(directory, "up:digest_worker", existing_go=True)
        check_deploy(directory, "up:digest_worker", existing_go=True, previously_stopped=True)
    print("PASS: atomic configuration, database guard, release ordering and rollback at every deployment stage")


if __name__ == "__main__":
    main()
