#!/usr/bin/env python3
"""Collect articles from a saved request and print JSON (Python 3.9+)."""

import argparse
from datetime import datetime
import json
import os
from pathlib import Path
import re
import sys
from urllib import error, parse, request
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError


SERVICE_DIR = Path(__file__).resolve().parents[1]
MAX_REQUEST_BYTES = 1024 * 1024
MAX_RESPONSE_BYTES = 32 * 1024 * 1024
RFC3339 = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$")
TOKEN_ASSIGNMENT = re.compile(r"^\s*(?:export\s+)?DIGEST_SERVICE_TOKEN\s*=(.*)$")


class SearchError(Exception):
    """A safe, user-facing error; never contains credentials or raw responses."""


class NoRedirect(request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def dotenv_token(path):
    """Read only a literal DIGEST_SERVICE_TOKEN assignment; never execute .env."""
    found = None
    try:
        with path.open(encoding="utf-8") as source:
            for line in source:
                match = TOKEN_ASSIGNMENT.match(line)
                if not match:
                    continue
                value = match.group(1).strip()
                if value.startswith(("'", '"')):
                    closing = value.find(value[0], 1)
                    if closing < 0 or (value[closing + 1:].strip() and not value[closing + 1:].lstrip().startswith("#")):
                        raise SearchError("Некорректная запись DIGEST_SERVICE_TOKEN в .env; используйте буквальное значение без подстановок.")
                    value = value[1:closing]
                else:
                    value = re.split(r"\s+#", value, maxsplit=1)[0].strip()
                found = value
    except FileNotFoundError:
        return None
    except (OSError, UnicodeError):
        raise SearchError("Не удалось прочитать .env сервиса.") from None
    return found


def service_token(dotenv_path):
    token = os.environ.get("DIGEST_SERVICE_TOKEN")
    if token is None:
        token = dotenv_token(dotenv_path)
    if token is None or len(token) < 32:
        raise SearchError("Задайте DIGEST_SERVICE_TOKEN длиной минимум 32 символа в окружении или services/digest/.env.")
    if any(ord(char) < 33 or ord(char) > 126 for char in token):
        raise SearchError("DIGEST_SERVICE_TOKEN должен содержать только печатные ASCII-символы без пробелов.")
    return token


def search_payload(path, now):
    try:
        with path.open("rb") as source:
            raw = source.read(MAX_REQUEST_BYTES + 1)
    except OSError:
        raise SearchError("Не удалось прочитать JSON-файл запроса.") from None
    if len(raw) > MAX_REQUEST_BYTES:
        raise SearchError("JSON-файл запроса больше 1 MiB.")
    try:
        payload = json.loads(raw)
    except (ValueError, UnicodeError):
        raise SearchError("В файле запроса некорректный JSON.") from None
    if not isinstance(payload, dict) or set(payload) != {"control_time", "query"}:
        raise SearchError("JSON должен содержать только control_time и query.")
    query = payload["query"]
    if not isinstance(query, str) or not query.strip() or len(query) > 2000:
        raise SearchError("query должен быть непустой строкой длиной до 2000 символов.")
    try:
        query.encode("utf-8")
    except UnicodeError:
        raise SearchError("query должен содержать корректный UTF-8.") from None
    if now:
        try:
            payload["control_time"] = datetime.now(ZoneInfo("Europe/Moscow")).isoformat(timespec="seconds")
        except ZoneInfoNotFoundError:
            raise SearchError("В системе нет часового пояса Europe/Moscow; установите системную базу tzdata.") from None
    control = payload["control_time"]
    if not isinstance(control, str) or not RFC3339.fullmatch(control):
        raise SearchError("control_time должен быть в RFC3339 с явным часовым поясом.")
    try:
        datetime.fromisoformat(control.replace("Z", "+00:00"))
    except ValueError:
        raise SearchError("Некорректная дата control_time.") from None
    return payload


def endpoint(base_url):
    try:
        parts = parse.urlsplit(base_url)
        valid = parts.scheme in ("http", "https") and parts.hostname and parts.username is None and parts.password is None
        valid = valid and not parts.query and not parts.fragment and parts.port != 0
    except ValueError:
        valid = False
    if not valid or any(ord(char) <= 32 for char in base_url):
        raise SearchError("--url должен быть HTTP(S)-адресом сервиса без логина, пароля, query и fragment.")
    return parse.urlunsplit((parts.scheme, parts.netloc, parts.path.rstrip("/") + "/v1/collect", "", ""))


def http_error_message(response, token):
    if 300 <= response.code < 400:
        return f"Сервис вернул перенаправление HTTP {response.code}. Проверьте --url; токен по перенаправлению не отправлялся."
    message = "Запрос отклонён сервисом."
    try:
        body = json.loads(response.read(64 * 1024))
        if isinstance(body, dict) and isinstance(body.get("message"), str):
            message = body["message"].replace(token, "[скрыто]")
            message = "".join(char for char in message if char.isprintable())[:1000]
    except (ValueError, UnicodeError, OSError):
        pass
    return f"HTTP {response.code}: {message}"


def submit(url, payload, token, timeout):
    body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = request.Request(url, data=body, method="POST", headers={
        "Authorization": "Bearer " + token,
        "Content-Type": "application/json",
        "Accept": "application/json",
    })
    # Only the URL explicitly selected by the caller receives the bearer token.
    opener = request.build_opener(request.ProxyHandler({}), NoRedirect())
    try:
        with opener.open(req, timeout=timeout) as response:
            raw = response.read(MAX_RESPONSE_BYTES + 1)
    except error.HTTPError as exc:
        try:
            message = http_error_message(exc, token)
        finally:
            exc.close()
        raise SearchError(message) from None
    except (error.URLError, OSError, ValueError):
        raise SearchError("Не удалось получить ответ сервиса. Проверьте --url, запущенный контейнер и таймаут; автоматического повтора не было.") from None
    if len(raw) > MAX_RESPONSE_BYTES:
        raise SearchError("Ответ сервиса больше 32 MiB.")
    try:
        result = json.loads(raw)
    except (ValueError, UnicodeError):
        raise SearchError("Сервис вернул некорректный JSON.") from None
    if not isinstance(result, dict) or result.get("status") != "completed":
        raise SearchError("Сервис не вернул завершённый запуск.")
    collection = result.get("collection")
    if result.get("mode") != "collection" or not isinstance(result.get("id"), str) or not result["id"]:
        raise SearchError("Сервис не вернул запуск сбора статей с id.")
    if not isinstance(collection, dict) or not isinstance(collection.get("articles"), list):
        raise SearchError("В ответе сервиса отсутствует collection.articles.")
    return result


def save_result(path, result):
    encoded = (json.dumps(result, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    try:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    except FileExistsError:
        raise SearchError("Файл --output уже существует; выберите новое имя.") from None
    except OSError:
        raise SearchError("Не удалось создать файл --output; проверьте родительскую папку и права.") from None
    try:
        with os.fdopen(fd, "wb") as output:
            output.write(encoded)
    except OSError:
        path.unlink(missing_ok=True)
        raise SearchError("Не удалось сохранить полный JSON-ответ.") from None


def validate_output(path):
    if os.path.lexists(path):
        raise SearchError("Файл --output уже существует; выберите новое имя.")
    if not path.parent.is_dir() or not os.access(path.parent, os.W_OK):
        raise SearchError("Родительская папка --output должна существовать и быть доступной для записи.")


def main(argv=None):
    parser = argparse.ArgumentParser(description="Найти и загрузить статьи за заданные 24 часа, вывести JSON без генерации дайджеста. Поиск может расходовать токены Yandex.")
    parser.add_argument("file", nargs="?", type=Path, default=SERVICE_DIR / "examples/search.json", help="JSON с control_time и query (по умолчанию examples/search.json)")
    parser.add_argument("--now", action="store_true", help="использовать текущее время Москвы, не меняя JSON-файл")
    parser.add_argument("--url", default="http://127.0.0.1:8081", help="базовый адрес сервиса; перенаправления запрещены")
    parser.add_argument("--timeout", type=int, default=200, metavar="SECONDS", help="таймаут запроса в секундах, по умолчанию 200")
    parser.add_argument("--output", type=Path, help="сохранить полный ответ в новый JSON-файл с правами 0600")
    parser.add_argument("--check", action="store_true", help="проверить файл и настройки без HTTP-запроса")
    args = parser.parse_args(argv)
    try:
        if not 1 <= args.timeout <= 900:
            raise SearchError("--timeout должен быть от 1 до 900 секунд.")
        payload = search_payload(args.file, args.now)
        url = endpoint(args.url)
        token = service_token(SERVICE_DIR / ".env")
        if args.output is not None:
            validate_output(args.output)
        if args.check:
            print("Файл и настройки корректны. HTTP-запрос не отправлялся.")
            return 0
        result = submit(url, payload, token, args.timeout)
        if args.output is not None:
            save_result(args.output, result)
        print(json.dumps({"run_id": result["id"], **result["collection"]}, ensure_ascii=False, indent=2))
        return 0
    except SearchError as exc:
        print("Ошибка: " + str(exc), file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print("Запрос прерван. Автоматического повтора не было.", file=sys.stderr)
        return 130


if __name__ == "__main__":
    sys.exit(main())
