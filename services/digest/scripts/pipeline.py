#!/usr/bin/env python3
"""Run a web or uploaded-news pipeline through one private HTTP request."""

import argparse
import json
import os
from pathlib import Path
import re
import shutil
import sys
import tempfile

from export import FILES, save_files, validate_directory
from analyze import load_news
from search import MAX_REQUEST_BYTES, SERVICE_DIR, SearchError, endpoint, post_json, save_result, service_token, validate_control_time


def web_payload(path, now):
    try:
        with path.open("rb") as source:
            raw = source.read(MAX_REQUEST_BYTES + 1)
    except OSError:
        raise SearchError("Не удалось прочитать JSON запроса с сайтами.") from None
    if len(raw) > MAX_REQUEST_BYTES:
        raise SearchError("Запрос с сайтами превышает 1 MiB.")
    try:
        payload = json.loads(raw)
    except (ValueError, UnicodeError):
        raise SearchError("Некорректный JSON запроса с сайтами.") from None
    if not isinstance(payload, dict) or set(payload) != {"control_time", "sites"}:
        raise SearchError("Запрос должен содержать только control_time и массив sites; поисковый запрос задан сервером.")
    sites = payload["sites"]
    if not isinstance(sites, list) or not 1 <= len(sites) <= 5 or not all(isinstance(s, str) and s.strip() and len(s) <= 2048 for s in sites):
        raise SearchError("sites должен содержать от 1 до 5 адресов сайтов.")
    return {"control_time": validate_control_time(payload["control_time"], now), "sites": sites}


def validate_result(result):
    if not isinstance(result, dict) or result.get("status") != "completed":
        raise SearchError("Сервис не вернул завершённый результат полного цикла.")
    if not isinstance(result.get("run_id"), str) or not re.fullmatch(r"[a-f0-9]{32}", result["run_id"]):
        raise SearchError("В результате отсутствует корректный ID запуска.")
    news, analysis, files = result.get("news"), result.get("analysis"), result.get("files")
    if not isinstance(news, dict) or not isinstance(news.get("items"), list):
        raise SearchError("В результате отсутствует news.json с массивом items.")
    if not isinstance(analysis, dict) or not isinstance(analysis.get("groups"), dict) or not isinstance(analysis.get("recommended_order"), list):
        raise SearchError("В результате отсутствует analysis.json с группами событий.")
    if not isinstance(files, dict) or set(files) != set(FILES) or not all(isinstance(files[name], str) for name in FILES):
        raise SearchError("Сервис не вернул содержимое всех четырёх файлов.")
    if not isinstance(result.get("warnings"), list) or not all(isinstance(w, str) for w in result["warnings"]):
        raise SearchError("Сервис вернул некорректный список предупреждений.")


def save_pipeline(path, result):
    validate_directory(path)
    staged = None
    reserved = False
    try:
        staged = Path(tempfile.mkdtemp(prefix=".notfive-pipeline-", dir=path.parent))
        save_result(staged / "news.json", result["news"])
        save_result(staged / "analysis.json", result["analysis"])
        save_files(staged / "files", result["files"])
        path.mkdir(mode=0o700)
        reserved = True
        os.replace(staged, path)
        staged = None
        reserved = False
    except (OSError, UnicodeError):
        raise SearchError("Не удалось сохранить результат. Выберите новую папку и проверьте права; результат доступен в аудите по ID запуска.") from None
    finally:
        if staged is not None:
            shutil.rmtree(staged, ignore_errors=True)
        if reserved:
            try:
                path.rmdir()
            except OSError:
                pass


def main(argv=None):
    parser = argparse.ArgumentParser(description="Один запрос: сайты или news.json → анализ → четыре файла. Обращается к моделям Яндекса.")
    modes = parser.add_subparsers(dest="mode", required=True)
    web = modes.add_parser("web", help="собрать статьи с сайтов, выполнить анализ и экспорт")
    web.add_argument("file", nargs="?", type=Path, default=SERVICE_DIR / "examples/pipeline-web.json", help="JSON с control_time и sites; поисковый запрос задан сервером")
    web.add_argument("--now", action="store_true", help="взять текущее время Москвы, не меняя исходный JSON")
    news = modes.add_parser("news", help="обработать news.json без поиска и загрузки сайтов")
    news.add_argument("file", type=Path, help="news.json с контрольным временем и статьями")
    for mode, timeout in [(web, 600), (news, 400)]:
        mode.add_argument("--output-dir", type=Path, required=True, help="новая папка для news.json, analysis.json и files/")
        mode.add_argument("--url", default="http://127.0.0.1:8081", help="базовый адрес Go-сервиса")
        mode.add_argument("--timeout", type=int, default=timeout, metavar="SECONDS")
        mode.add_argument("--check", action="store_true", help="проверить вход и настройки без HTTP-запроса")
    args = parser.parse_args(argv)
    try:
        if not 1 <= args.timeout <= 1900:
            raise SearchError("--timeout должен быть от 1 до 1900 секунд.")
        payload = web_payload(args.file, args.now) if args.mode == "web" else load_news(args.file)
        if len(json.dumps(payload, ensure_ascii=False).encode("utf-8")) > MAX_REQUEST_BYTES:
            raise SearchError("Запрос превышает 1 MiB.")
        token = service_token(SERVICE_DIR / ".env")
        url = endpoint(args.url, "/v1/pipeline/" + args.mode)
        validate_directory(args.output_dir)
        if args.check:
            print("Вход, токен и папка доступны. HTTP-запрос не отправлялся.")
            return 0
        metadata = {}
        result = post_json(url, payload, token, args.timeout, metadata)
        if metadata.get("run_id"):
            print("ID запуска: " + metadata["run_id"], file=sys.stderr)
        validate_result(result)
        save_pipeline(args.output_dir, result)
        print(str(args.output_dir.absolute()))
        print("Сохранены news.json, analysis.json и четыре файла в files/.")
        for warning in result["warnings"]:
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
