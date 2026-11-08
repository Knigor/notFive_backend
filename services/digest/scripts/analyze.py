#!/usr/bin/env python3
"""Send an existing news.json to the private analysis API (Python 3.9+)."""

import argparse
import json
from pathlib import Path
import sys

from search import (
    MAX_REQUEST_BYTES, SERVICE_DIR, SearchError, endpoint, post_json,
    save_result, service_token, validate_output,
)


def load_news(path):
    try:
        with path.open("rb") as source:
            raw = source.read(MAX_REQUEST_BYTES + 1)
    except OSError:
        raise SearchError("Не удалось прочитать news.json.") from None
    if len(raw) > MAX_REQUEST_BYTES:
        raise SearchError("news.json превышает лимит запроса 1 MiB.")
    try:
        payload = json.loads(raw)
    except (ValueError, UnicodeError):
        raise SearchError("В файле новостей некорректный JSON.") from None
    if not isinstance(payload, dict) or not isinstance(payload.get("items"), list):
        raise SearchError("Ожидается news.json с массивом items.")
    if not isinstance(payload.get("dataset"), str) or not isinstance(payload.get("control_time"), str):
        raise SearchError("В news.json нужны dataset и control_time.")
    return payload


def main(argv=None):
    parser = argparse.ArgumentParser(description="Проанализировать готовый news.json через Alice AI LLM Flash без веб-поиска. Запрос расходует токены Yandex.")
    parser.add_argument("file", type=Path, help="готовый news.json, например /tmp/notfive-news.json")
    parser.add_argument("--url", default="http://127.0.0.1:8081", help="базовый адрес Go-сервиса")
    parser.add_argument("--timeout", type=int, default=200, metavar="SECONDS")
    parser.add_argument("--output", type=Path, help="сохранить analysis.json в новый файл с правами 0600")
    parser.add_argument("--check", action="store_true", help="проверить файл и токен без обращения к API")
    args = parser.parse_args(argv)
    try:
        if not 1 <= args.timeout <= 900:
            raise SearchError("--timeout должен быть от 1 до 900 секунд.")
        payload = load_news(args.file)
        url = endpoint(args.url, "/v1/analyze")
        token = service_token(SERVICE_DIR / ".env")
        if args.output is not None:
            validate_output(args.output)
        if args.check:
            print("Файл и токен доступны. HTTP-запрос не отправлялся; проверка дат и доступности модели выполняется сервисом.")
            return 0
        metadata = {}
        result = post_json(url, payload, token, args.timeout, metadata)
        if not isinstance(result, dict) or not isinstance(result.get("groups"), dict) or not isinstance(result.get("recommended_order"), list) or not isinstance(result.get("excluded"), dict):
            raise SearchError("Сервис не вернул analysis.json с groups, recommended_order и excluded.")
        if args.output is not None:
            save_result(args.output, result)
        if metadata.get("run_id"):
            print("ID запуска: " + metadata["run_id"], file=sys.stderr)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        return 0
    except SearchError as exc:
        print("Ошибка: " + str(exc), file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print("Запрос прерван. Автоматического повтора не было.", file=sys.stderr)
        return 130


if __name__ == "__main__":
    sys.exit(main())
