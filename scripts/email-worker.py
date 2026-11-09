#!/usr/bin/env python3
"""Run the production email consumer on this computer over an SSH database tunnel."""

import argparse
import fcntl
import ipaddress
import json
import os
from pathlib import Path
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import threading
import time
import uuid


CONTAINER = "notfive-email-worker"
SESSION_LABEL = "io.notfive.email-worker-session"
REQUIRED_ENV = (
    "MAILER_DSN", "MAIL_FROM_ADDRESS", "APP_SECRET", "POSTGRES_DB",
    "POSTGRES_USER", "POSTGRES_PASSWORD", "DEFAULT_URI",
)
REMOTE_INSPECT = r'''
import json, subprocess, sys
try:
    app, database = json.loads(subprocess.check_output([
        'docker', 'inspect', 'notfive-app-1', 'notfive-database-1'
    ], stderr=subprocess.DEVNULL))
    env = dict(value.split('=', 1) for value in app['Config']['Env'] if '=' in value)
    keys = ('MAILER_DSN', 'MAIL_FROM_ADDRESS', 'APP_SECRET', 'POSTGRES_DB',
            'POSTGRES_USER', 'POSTGRES_PASSWORD', 'DEFAULT_URI')
    result = {
        'image': app['Config']['Image'],
        'database_ip': database['NetworkSettings']['Networks']['notfive_default']['IPAddress'],
        'mode': env.get('EMAIL_DELIVERY_MODE', 'sync'),
        'environment': {key: env.get(key, '') for key in keys},
    }
    print(json.dumps(result))
except Exception:
    sys.exit(1)
'''

# No message is dispatched or consumed in check mode. Exceptions are deliberately
# replaced by static error codes before anything reaches the local terminal.
CHECK_PHP = r'''
require '/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/app/.env');
try {
    if (!class_exists('App\\Mail\\Message\\SendEmailConfirmation')) {
        echo "CHECK image_queue_unavailable\n"; exit(2);
    }
    $kernel = new App\Kernel('prod', false);
    $kernel->boot();
    $connection = $kernel->getContainer()->get('doctrine')->getConnection();
    $connection->fetchOne('SELECT 1');
    $counts = $connection->fetchAllAssociative("SELECT queue_name, COUNT(*) AS total FROM messenger_messages WHERE queue_name IN ('email', 'email_failed') GROUP BY queue_name");
    $pending = 0; $failed = 0;
    foreach ($counts as $row) {
        if ($row['queue_name'] === 'email') { $pending = (int) $row['total']; }
        if ($row['queue_name'] === 'email_failed') { $failed = (int) $row['total']; }
    }
    printf("CHECK database_ok queued=%d failed=%d\n", $pending, $failed);
} catch (Throwable) {
    echo "CHECK database_unavailable\n"; exit(3);
}
try {
    $factory = new App\Mail\SmtpTimeoutTransportFactory(new Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory());
    $dsn = Symfony\Component\Mailer\Transport\Dsn::fromString(getenv('MAILER_DSN'));
    if (!$dsn->getUser() || !$dsn->getPassword()) {
        echo "CHECK smtp_credentials_missing\n"; exit(4);
    }
    $transport = $factory->create($dsn);
    $transport->start();
    $transport->stop();
    echo "CHECK smtp_authenticated_no_email_sent\n";
} catch (Throwable) {
    echo "CHECK smtp_unavailable\n"; exit(4);
}
'''


class WorkerError(Exception):
    """Only static, non-sensitive messages may be passed to this exception."""


def parser():
    result = argparse.ArgumentParser(description="Локальная отправка production-писем через SSH. Ctrl+C останавливает обработчик.")
    result.add_argument("--host", default="91.192.156.55", help="SSH-хост VPS")
    result.add_argument("--user", default="root", help="Пользователь SSH")
    result.add_argument("--identity", type=Path, default=Path("~/.ssh/notfive_deploy"), help="Существующий SSH-ключ")
    result.add_argument("--port", type=int, default=22, help="Порт SSH")
    result.add_argument("--local-port", type=int, default=15433, help="Локальный порт туннеля, только 127.0.0.1")
    result.add_argument("--state-dir", type=Path, default=Path.home() / ".local/state/notfive-email", help="Локальный каталог блокировки процесса")
    modes = result.add_mutually_exclusive_group()
    modes.add_argument("--check", action="store_true", help="Проверить очередь и SMTP-авторизацию, не отправляя писем")
    modes.add_argument("--once", action="store_true", help="Обработать не более одного задания, ожидать до 10 секунд")
    modes.add_argument("--stop", action="store_true", help="Остановить только обработчик, запущенный этим скриптом")
    modes.add_argument("--status", action="store_true", help="Показать состояние локального процесса")
    return result


def validate_args(args):
    if not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}", args.host):
        raise WorkerError("Некорректный SSH-хост.")
    if not re.fullmatch(r"[a-z_][a-z0-9_-]{0,63}", args.user):
        raise WorkerError("Некорректный пользователь SSH.")
    if not 1 <= args.port <= 65535 or not 1024 <= args.local_port <= 65535:
        raise WorkerError("Некорректный порт.")
    args.identity = args.identity.expanduser().resolve()
    args.state_dir = args.state_dir.expanduser().resolve()
    if not (args.stop or args.status) and not args.identity.is_file():
        raise WorkerError("SSH-ключ не найден; укажите существующий файл через --identity.")


def ssh_command(args):
    return [
        "ssh", "-i", str(args.identity), "-p", str(args.port),
        "-o", "IdentitiesOnly=yes", "-o", "BatchMode=yes",
        "-o", "StrictHostKeyChecking=yes", "-o", "ConnectTimeout=10",
        "-o", "ServerAliveInterval=15", "-o", "ServerAliveCountMax=2",
        f"{args.user}@{args.host}",
    ]


def validate_metadata(data):
    if not isinstance(data, dict) or set(data) != {"image", "database_ip", "mode", "environment"}:
        raise WorkerError("VPS вернул некорректную конфигурацию.")
    if not isinstance(data["image"], str) or not re.fullmatch(r"ghcr\.io/knigor/notfive_backend@sha256:[a-f0-9]{64}", data["image"]):
        raise WorkerError("На VPS должен быть развёрнут проверенный образ notFive по digest.")
    try:
        address = ipaddress.IPv4Address(data["database_ip"])
    except (ValueError, TypeError):
        raise WorkerError("Не удалось определить внутренний адрес PostgreSQL.") from None
    if not address.is_private or address.is_loopback or address.is_multicast:
        raise WorkerError("PostgreSQL должен находиться во внутренней сети Docker.")
    if data["mode"] != "queued":
        raise WorkerError("На VPS ещё не включён EMAIL_DELIVERY_MODE=queued; сначала выполните релиз очереди.")
    env = data["environment"]
    if not isinstance(env, dict) or set(env) != set(REQUIRED_ENV):
        raise WorkerError("Не хватает настроек обработчика на VPS.")
    if any(not isinstance(value, str) or not value or len(value) > 16384 or any(ord(char) < 32 or ord(char) == 127 for char in value) for value in env.values()):
        raise WorkerError("Настройки обработчика на VPS неполны или содержат недопустимые символы.")
    if not env["MAILER_DSN"].startswith(("smtp://", "smtps://")):
        raise WorkerError("Локальному обработчику требуется настроенный SMTP-транспорт.")
    if not env["DEFAULT_URI"].startswith("https://"):
        raise WorkerError("Ссылки подтверждения должны вести на HTTPS-адрес production API.")
    return data


def metadata(args):
    try:
        result = subprocess.run(ssh_command(args) + ["python3 -"], input=REMOTE_INSPECT, text=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=25, check=True)
        if len(result.stdout) > 131072:
            raise WorkerError("VPS вернул некорректную конфигурацию.")
        return validate_metadata(json.loads(result.stdout))
    except (OSError, subprocess.SubprocessError, ValueError):
        raise WorkerError("Не удалось получить настройки с VPS. Проверьте SSH-доступ и работающие контейнеры.") from None


def environment_file(data, args):
    values = dict(data["environment"], APP_ENV="prod", APP_DEBUG="0", APP_AUTO_MIGRATE="0", EMAIL_DELIVERY_MODE="queued", POSTGRES_HOST="127.0.0.1" if sys.platform.startswith("linux") else "host.docker.internal", POSTGRES_PORT=str(args.local_port))
    fd, filename = tempfile.mkstemp(prefix="worker-env-", dir=args.state_dir)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as stream:
            os.fchmod(stream.fileno(), 0o600)
            for key, value in values.items():
                # Docker --env-file takes literal values; shell/dotenv expansion is not used.
                stream.write(key + "=" + value + "\n")
    except BaseException:
        Path(filename).unlink(missing_ok=True)
        raise
    return Path(filename)


def open_lock(args):
    args.state_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(args.state_dir, 0o700)
    descriptor = os.open(args.state_dir / "worker.lock", os.O_RDWR | os.O_CREAT | getattr(os, "O_NOFOLLOW", 0), 0o600)
    return os.fdopen(descriptor, "r+", encoding="ascii")


def running_pid(lock):
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        lock.seek(0)
        value = lock.read().strip()
        return int(value) if value.isdigit() and int(value) > 1 else -1
    fcntl.flock(lock, fcntl.LOCK_UN)
    return None


def control(args):
    with open_lock(args) as lock:
        pid = running_pid(lock)
        if pid is None:
            print("Локальный обработчик не запущен.")
            return 0
        if args.status:
            print("Локальный обработчик запущен" + (f" (PID {pid})." if pid > 1 else "."))
            return 0
        if pid < 2:
            raise WorkerError("Обработчик запускается; повторите остановку через несколько секунд.")
        try:
            os.kill(pid, signal.SIGTERM)
        except ProcessLookupError:
            return 0
        for _ in range(90):
            if running_pid(lock) is None:
                print("Локальный обработчик остановлен.")
                return 0
            time.sleep(0.5)
        raise WorkerError("Отправлен сигнал остановки; процесс ещё завершает текущую операцию.")


def ensure_free_port(port):
    try:
        with socket.socket() as listener:
            listener.bind(("127.0.0.1", port))
    except OSError:
        raise WorkerError("Локальный порт туннеля занят; укажите другой --local-port.") from None


class Supervisor:
    def __init__(self, args):
        self.args = args
        self.stopping = threading.Event()
        self.session = uuid.uuid4().hex
        self.tunnel = None
        self.worker = None
        self.env_path = None
        self.owns_container = False
        self.image = None
        self.database_ip = None

    def stop(self, *_):
        self.stopping.set()

    def command(self, command, *, timeout=30, capture=False, interruptible=True):
        process = None
        try:
            process = subprocess.Popen(command, stdout=subprocess.PIPE if capture else subprocess.DEVNULL, stderr=subprocess.DEVNULL, text=True)
            deadline = time.monotonic() + timeout
            while True:
                if interruptible and self.stopping.is_set():
                    raise WorkerError("Обработчик останавливается.")
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise WorkerError("Локальная команда не завершилась вовремя; проверьте Docker и SSH.")
                try:
                    output, _ = process.communicate(timeout=min(0.5, remaining))
                    return subprocess.CompletedProcess(command, process.returncode, stdout=output or "")
                except subprocess.TimeoutExpired:
                    pass
        except (OSError, subprocess.SubprocessError):
            raise WorkerError("Локальная команда не выполнилась; проверьте Docker и SSH.") from None
        finally:
            if process is not None and process.poll() is None:
                self.terminate(process)

    def has_container(self):
        return self.command(["docker", "inspect", "--format", "{{.Id}}", CONTAINER], capture=True).returncode == 0

    def stop_worker(self):
        if self.owns_container:
            label = self.command(["docker", "inspect", "--format", '{{index .Config.Labels "' + SESSION_LABEL + '"}}', CONTAINER], capture=True, interruptible=False)
            if label.returncode == 0 and label.stdout.strip() == self.session:
                self.command(["docker", "stop", "--time", "30", CONTAINER], timeout=35, interruptible=False)
            self.owns_container = False
        if self.worker is not None:
            self.terminate(self.worker)
            self.worker = None

    @staticmethod
    def terminate(process):
        if process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)

    def start_tunnel(self, address):
        if self.tunnel is not None and self.tunnel.poll() is None and self.database_ip == address:
            return
        if self.tunnel is not None:
            self.terminate(self.tunnel)
        ensure_free_port(self.args.local_port)
        command = ssh_command(self.args)
        command[-1:-1] = ["-N", "-T", "-o", "ExitOnForwardFailure=yes", "-L", f"127.0.0.1:{self.args.local_port}:{address}:5432"]
        self.tunnel = subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.database_ip = address
        for _ in range(50):
            if self.stopping.wait(0.1) or self.tunnel.poll() is not None:
                raise WorkerError("SSH-туннель не запущен. Проверьте доступ и локальный порт.")
            try:
                with socket.create_connection(("127.0.0.1", self.args.local_port), timeout=0.2):
                    return
            except OSError:
                pass
        raise WorkerError("SSH-туннель не запустился вовремя.")

    def prepare(self):
        data = metadata(self.args)
        if self.has_container():
            raise WorkerError("Контейнер notfive-email-worker уже существует. Этот процесс не будет его останавливать.")
        if data["image"] != self.image:
            print("Загружаю образ текущего production-релиза…", flush=True)
            pulled = self.command(["docker", "pull", "--platform", "linux/amd64", data["image"]], timeout=300)
            if pulled.returncode != 0:
                raise WorkerError("Не удалось получить production-образ. Проверьте Docker и доступ к GHCR.")
            self.image = data["image"]
        self.start_tunnel(data["database_ip"])
        if self.env_path is not None:
            self.env_path.unlink(missing_ok=True)
        self.env_path = environment_file(data, self.args)
        return data

    def docker_command(self, command):
        return [
            "docker", "run", "--rm", "--platform", "linux/amd64", "--stop-signal", "SIGTERM", "--name", CONTAINER,
            "--label", f"{SESSION_LABEL}={self.session}", "--env-file", str(self.env_path),
            *(["--network", "host"] if sys.platform.startswith("linux") else []), "--entrypoint", "php",
            self.image, "-d", "zend.exception_ignore_args=1", "-d", "display_errors=0",
            "-d", "log_errors=0", *command,
        ]

    def check(self):
        self.prepare()
        self.owns_container = True
        result = self.command(self.docker_command(["-r", CHECK_PHP]), timeout=40, capture=True)
        # Symfony may write unexpected output on startup. Only accept static check lines.
        lines = [line for line in result.stdout.splitlines() if re.fullmatch(r"CHECK (?:image_queue_unavailable|database_unavailable|smtp_unavailable|smtp_credentials_missing|smtp_authenticated_no_email_sent|database_ok queued=\d+ failed=\d+)", line)]
        for line in lines:
            print(line)
        if result.returncode != 0:
            raise WorkerError("Проверка не прошла; очередь не обрабатывалась и письма не отправлялись.")
        if "CHECK smtp_authenticated_no_email_sent" not in lines:
            raise WorkerError("Не удалось подтвердить результат проверки.")
        print("База через SSH и SMTP доступны. Письма не отправлялись.")
        return 0

    def consume(self):
        self.prepare()
        limit = ["--limit=1", "--time-limit=10"] if self.args.once else ["--time-limit=60"]
        command = ["bin/console", "messenger:consume", "email", *limit, "--sleep=1", "--memory-limit=128M", "--no-interaction", "--quiet"]
        self.owns_container = True
        self.worker = subprocess.Popen(self.docker_command(command), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        while self.worker.poll() is None:
            if self.stopping.wait(0.5):
                self.stop_worker()
                return 0
            if self.tunnel is None or self.tunnel.poll() is not None:
                self.stop_worker()
                raise WorkerError("SSH-соединение прервалось; очередь остаётся на VPS.")
        code = self.worker.returncode
        self.stop_worker()
        if code != 0:
            raise WorkerError("Обработчик завершился с ошибкой; задания сохраняются в очереди. Выполните --check.")
        return 0

    def run(self):
        if self.args.check:
            return self.check()
        print("Локальный обработчик запущен. Письма отправляются только из production-очереди. Ctrl+C — остановка.", flush=True)
        last_error = None
        while not self.stopping.is_set():
            try:
                self.consume()
                if self.args.once:
                    print("Однократная обработка завершена; состояние очереди можно проверить через --check.")
                    return 0
                if last_error is not None:
                    print("Соединение восстановлено; обработка очереди продолжается.", flush=True)
                last_error = None
            except WorkerError as error:
                if self.args.once:
                    raise
                message = str(error)
                if message != last_error and not self.stopping.is_set():
                    print(message + " Повтор через 5 секунд.", file=sys.stderr, flush=True)
                last_error = message
                if self.stopping.wait(5):
                    break
        return 0

    def close(self):
        try:
            self.stop_worker()
        finally:
            try:
                if self.tunnel is not None:
                    self.terminate(self.tunnel)
            finally:
                if self.env_path is not None:
                    self.env_path.unlink(missing_ok=True)


def main(argv=None):
    os.umask(0o077)
    args = parser().parse_args(argv)
    validate_args(args)
    if args.stop or args.status:
        return control(args)
    if any(shutil.which(command) is None for command in ("ssh", "docker")):
        raise WorkerError("Нужны установленные Docker и SSH.")
    with open_lock(args) as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise WorkerError("Локальный обработчик уже запущен; используйте --status или --stop.") from None
        lock.seek(0)
        lock.truncate()
        lock.write(str(os.getpid()))
        lock.flush()
        supervisor = Supervisor(args)
        signal.signal(signal.SIGTERM, supervisor.stop)
        signal.signal(signal.SIGINT, supervisor.stop)
        try:
            return supervisor.run()
        finally:
            supervisor.close()
            lock.seek(0)
            lock.truncate()


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (WorkerError, OSError) as error:
        # OSError may include a path or a secret-bearing argument; never print it.
        message = str(error) if isinstance(error, WorkerError) else "Не удалось запустить обработчик; проверьте локальное окружение."
        print(message, file=sys.stderr)
        sys.exit(1)
