# Запрос к сборщику

[search.json](search.json) содержит тему поиска и фиксированное контрольное время `2026-11-08T13:01:59+03:00`. Это вход `POST /v1/collect`, не обучающий набор и не готовые статьи. Для другого периода измените `control_time`: публикации отбираются строго после T−24 часа и не позже T.

Из папки `services/digest` можно обратиться к API напрямую:

```sh
read -r -s DIGEST_SERVICE_TOKEN
curl --fail-with-body --max-time 200 \
  http://127.0.0.1:8081/v1/collect \
  -H "Authorization: Bearer $DIGEST_SERVICE_TOKEN" \
  -H 'Content-Type: application/json' \
  --data-binary @examples/search.json
```

Тестовый клиент:

```sh
python3 scripts/search.py --check
python3 scripts/search.py
python3 scripts/search.py --now
```

`--check` проверяет настройки без запроса. `--now` подставляет текущее время Москвы в отправляемую копию, не меняя файл. Токен читается из окружения или `.env` сервиса. Обычный запуск может расходовать токены Yandex на поиск.

В консоли выводятся `run_id`, период, статьи с текстами, исключения, счётчики и предупреждения. Полный ответ API имеет `id`, вложенный `collection` и аудит `research`; `--output /tmp/notfive-collection.json` сохраняет его в новый файл с правами `0600`.

Контракт: [README сервиса](../README.md#http-контракт-для-symfony), [OpenAPI](../openapi.json). Домены задаются оператором через `YANDEX_SEARCH_DOMAINS`; каталог источников: [SOURCES.md](../../../docs/case/SOURCES.md).
