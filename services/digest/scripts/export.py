#!/usr/bin/env python3
"""Generate four digest artifacts via the private Go API (Python 3.9+)."""

import argparse
import json
import os
from pathlib import Path
import shutil
import sys
import tempfile

from analyze import load_news
from search import MAX_REQUEST_BYTES, SERVICE_DIR, SearchError, endpoint, post_json, service_token


FILES = ("digest.md", "post.md", "email.md", "journal.csv")


def load_analysis(path):
    try:
        with path.open("rb") as source:
            raw = source.read(MAX_REQUEST_BYTES + 1)
    except OSError:
        raise SearchError("Не удалось прочитать analysis.json.") from None
    if len(raw) > MAX_REQUEST_BYTES:
        raise SearchError("analysis.json превышает лимит запроса 1 MiB.")
    try:
        result = json.loads(raw)
    except (ValueError, UnicodeError):
        raise SearchError("В файле анализа некорректный JSON.") from None
    if not isinstance(result, dict) or not isinstance(result.get("groups"), dict) or not isinstance(result.get("recommended_order"), list) or not isinstance(result.get("excluded"), dict):
        raise SearchError("Ожидается analysis.json с groups, recommended_order и excluded.")
    return result


def validate_directory(path):
    if os.path.lexists(path):
        raise SearchError("Папка --output-dir уже существует; выберите новое имя.")
    if not path.parent.is_dir() or not os.access(path.parent, os.W_OK):
        raise SearchError("Родительская папка --output-dir должна существовать и быть доступной для записи.")


def save_files(path, files):
    """Stage all files, reserve a new destination, then publish the directory."""
    validate_directory(path)
    staged = None
    reserved = False
    try:
        staged = Path(tempfile.mkdtemp(prefix=".notfive-export-", dir=path.parent))
        for name in FILES:
            fd = os.open(staged / name, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, "w", encoding="utf-8", newline="") as output:
                output.write(files[name])
        # mkdir is exclusive: even an empty pre-existing directory is never replaced.
        path.mkdir(mode=0o700)
        reserved = True
        os.replace(staged, path)
        staged = None
        reserved = False
    except FileExistsError:
        raise SearchError("Папка --output-dir уже существует; выберите новое имя. Результат остался в аудите сервиса.") from None
    except (OSError, UnicodeError):
        raise SearchError("Не удалось сохранить четыре файла. Проверьте права и свободное место; результат остался в аудите сервиса.") from None
    finally:
        if staged is not None:
            shutil.rmtree(staged, ignore_errors=True)
        if reserved:
            try:
                path.rmdir()
            except OSError:
                pass


def main(argv=None):
    parser = argparse.ArgumentParser(description="Сформировать digest.md, post.md, email.md и journal.csv из анализа и исходных новостей. Генерация через Alice расходует токены Yandex.")
    parser.add_argument("file", type=Path, help="готовый analysis.json")
    parser.add_argument("--news", type=Path, required=True, help="исходный news.json того же набора и периода")
    parser.add_argument("--output-dir", type=Path, required=True, help="новая папка для четырёх файлов; существующая не перезаписывается")
    parser.add_argument("--url", default="http://127.0.0.1:8081", help="базовый адрес Go-сервиса")
    parser.add_argument("--timeout", type=int, default=200, metavar="SECONDS")
    parser.add_argument("--check", action="store_true", help="проверить файлы, размер запроса, папку и токен без вызова API")
    args = parser.parse_args(argv)
    try:
        if not 1 <= args.timeout <= 900:
            raise SearchError("--timeout должен быть от 1 до 900 секунд.")
        payload = {"analysis": load_analysis(args.file), "news": load_news(args.news)}
        if len(json.dumps(payload, ensure_ascii=False).encode("utf-8")) > MAX_REQUEST_BYTES:
            raise SearchError("Общий запрос analysis + news превышает 1 MiB.")
        url = endpoint(args.url, "/v1/export")
        token = service_token(SERVICE_DIR / ".env")
        validate_directory(args.output_dir)
        if args.check:
            print("Файлы, токен и папка доступны. HTTP-запрос не отправлялся; соответствие анализа новостям проверяется сервисом.")
            return 0
        metadata = {}
        result = post_json(url, payload, token, args.timeout, metadata)
        if metadata.get("run_id"):
            print("ID запуска: " + metadata["run_id"], file=sys.stderr)
        files = result.get("files") if isinstance(result, dict) else None
        if not isinstance(files, dict) or set(files) != set(FILES) or not all(isinstance(files[name], str) for name in FILES):
            raise SearchError("Сервис не вернул содержимое всех четырёх файлов.")
        warnings = result.get("warnings")
        if not isinstance(warnings, list) or not all(isinstance(warning, str) for warning in warnings):
            raise SearchError("Сервис вернул некорректный список предупреждений.")
        save_files(args.output_dir, files)
        for name in FILES:
            print(str((args.output_dir / name).absolute()))
        for warning in warnings:
            print("Предупреждение: " + "".join(char for char in warning if char.isprintable()), file=sys.stderr)
        return 0
    except SearchError as exc:
        print("Ошибка: " + str(exc), file=sys.stderr)
        return 1
    except KeyboardInterrupt:
        print("Запрос прерван. Автоматического повтора не было.", file=sys.stderr)
        return 130


if __name__ == "__main__":
    sys.exit(main())
