# Модельный модуль notFive

Модуль принимает статьи, вызывает YandexGPT, проверяет ответы и возвращает структурированный результат для других частей проекта. Команда может вызвать Python-функцию напрямую или подключиться по JSON API. Пример входа находится в `docs/case/fixtures/five-articles.input.json`.

## Запуск в PowerShell

Из корня проекта выполни:

```powershell
python -m venv notfive_py/.venv
& .\notfive_py\.venv\Scripts\python.exe -m pip install --upgrade pip setuptools
& .\notfive_py\.venv\Scripts\python.exe -m pip install -r notfive_py/requirements.txt
$env:NOTFIVE_API_TOKEN = (& .\notfive_py\.venv\Scripts\python.exe -c "import secrets; print(secrets.token_urlsafe(32))").Trim()
$env:NOTFIVE_YANDEX_FOLDER_ID = '<ID каталога AI Studio>'
$env:NOTFIVE_YANDEX_IAM_TOKEN = (yc iam create-token).Trim()
& .\notfive_py\.venv\Scripts\python.exe -m uvicorn notfive_py.app:app --host 127.0.0.1 --port 8090
```

Документация API доступна по адресу `http://127.0.0.1:8090/docs`. Для прямого API AI Studio вместо IAM-токена можно передать серверную переменную `NOTFIVE_YANDEX_API_KEY`. Модель задаётся через `NOTFIVE_YANDEX_MODEL`, по умолчанию используется `yandexgpt-5-lite`. IAM-токен для локальной проверки надо обновлять по истечении срока действия. [Получение IAM-токена](https://yandex.cloud/ru/docs/iam/operations/iam-token/create-for-sa)

Для запуска, чтения результата и скачивания передавай `Authorization: Bearer <NOTFIVE_API_TOKEN>`. В OpenAPI токен вводится через **Authorize**. Передай тот же токен клиенту через переменную окружения, если сервер и клиент запускаются в разных терминалах. Токен notFive отделён от ключей Яндекса и даёт доступ ко всем выпускам этого экземпляра. При сетевой интеграции используй HTTPS и задай точные имена хостов в `NOTFIVE_ALLOWED_HOSTS` через запятую. По умолчанию разрешены `localhost` и `127.0.0.1`.

Яндекс запланировал отключение YandexGPT Lite 5 на 15 ноября 2026 года. Модель прямых запросов можно сменить через `NOTFIVE_YANDEX_MODEL` при перезапуске. [Сроки поддержки](https://aistudio.yandex.ru/ru/docs/ai-studio/release-notes/deprecations)

Для извлечения через Workflows задай `NOTFIVE_YANDEX_WORKFLOW_ID` с конфигурацией из `docs/case/fixtures/notfive-extract.yawl.yaml` версии `v3`. В этом режиме извлечение использует промпт Workflow. Выборка разделов словаря работает при прямом извлечении через AI Studio. Группировка и сверка в обоих режимах идут через `NOTFIVE_YANDEX_MODEL`.

## Как работает конвейер

1. API принимает `{control_time, articles}`. Даты с часовым поясом приводятся к Москве. Материалы вне окна `(T−24 ч, T]`, с неизвестной датой и недоступным текстом получают причину в журнале.
2. Python подбирает до трёх разделов словаря по словам и фразам из заголовка и текста. Учитываются простые словоформы и варианты терминов. Модель получает результат вместе с правилом контекста из задания. Статья без совпадений тоже передаётся модели.
3. Модель выделяет утверждения с цитатами, а Python проверяет JSON, ID и наличие цитаты в исходном тексте. Для группировки передаются утверждение, цитата и соседний текст. При сверке Python добавляет до шести фрагментов из других статей с тем же CVE. Факт и ссылка добавляются в событие, только если модель сверки подтвердила, что фрагмент относится к тому же событию.
4. Python выбирает до пяти событий по четырём оценкам модели и возвращает события с источниками и цитатами, журнал и статистику вызовов. HTTP-адаптер также формирует `digest.md`, `post.md`, `email.html` и `journal.csv`.

## Вызов из Python

```python
import httpx

from notfive_py.domain import RunInput
from notfive_py.pipeline import analyze_articles
from notfive_py.provider import YandexProvider, YandexSettings


async def analyze(payload: dict) -> dict:
    settings = YandexSettings.from_env()
    async with httpx.AsyncClient(timeout=70) as client:
        provider = YandexProvider(settings, client)
        return await analyze_articles(RunInput.model_validate(payload), provider, lambda stage: None)
```

В результате `events` содержит `summary`, `post`, `consequences`, `priority`, `sources` и `facts`. У каждого факта есть `source_id` и `evidence` из оригинала. `claim` содержит формулировку модели, сверенную отдельным запросом. `journal` содержит запись на входную статью. `model_calls` показывает этапы и расход токенов. Смысловую точность формулировок и оценок нужно проверять на размеченных материалах.

## API

| Метод | Назначение |
|---|---|
| `POST /api/v1/runs` | Принять JSON и вернуть `202` с `id`. Повтор с тем же `Idempotency-Key` возвращает существующий ID для того же набора. |
| `GET /api/v1/runs/{id}` | Получить статус, стадию, события, журнал и данные о модельных вызовах. |
| `GET /api/v1/runs/{id}/artifacts/{filename}` | Скачать один из четырёх файлов завершённого запуска. |
| `GET /api/v1/example` | Получить локальный пример входных данных. |

```powershell
$headers = @{ Authorization = "Bearer $env:NOTFIVE_API_TOKEN" }
$inputJson = Get-Content -Raw -Encoding utf8 docs/case/fixtures/five-articles.input.json
$run = Invoke-RestMethod -Method Post -Uri 'http://127.0.0.1:8090/api/v1/runs' `
    -Headers $headers -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($inputJson))
do {
    Start-Sleep -Seconds 1
    $state = Invoke-RestMethod "http://127.0.0.1:8090/api/v1/runs/$($run.id)" -Headers $headers
} while ($state.status -in @('queued', 'running'))
if ($state.status -eq 'failed') { throw $state.error }
$state.result
```

## Проверки и границы прототипа

```powershell
& .\notfive_py\.venv\Scripts\python.exe -m unittest discover -s notfive_py -t . -v
```

Тесты используют воспроизводимые ответы модели и проверяют сценарии задания, формат выдачи, ранжирование и API. Качество генерации оценивается отдельным прогоном с Яндексом на размеченных материалах.

Сервис рассчитан на один процесс Uvicorn. Тело запроса ограничено 16 МиБ, приём ограничен 50 записями за запуск и 24 000 символов на текст. Статьи со ссылками, содержащими распознанные параметры доступа, исключаются с причиной в журнале. Передавай публичные тексты и ссылки из реестра задания.

SQLite сохраняет хеш входных данных и итоговый результат новых запусков. Срок хранения новых запусков по умолчанию 30 дней. Его можно задать через `NOTFIVE_RUN_RETENTION_DAYS` от 1 до 365. Истёкшие записи перестают выдаваться через API и удаляются при старте сервера или создании следующего запуска. Для перевода старых записей на этот режим задай `NOTFIVE_MIGRATE_LEGACY_RUNS=1` при запуске. Старый исходный JSON в базе будет заменён хешем, а срок хранения начнётся с момента миграции. При выдаче старый журнал CSV пересобирается безопасно, а выпуск с распознанным параметром доступа в ссылке закрывается целиком.

Для сравнения живых запусков используй [набор проверок](evals/README.md). Результат содержит `input_sha256`, версию промпта, а для прямых вызовов записываются `temperature`, лимит токенов, отпечатки запросов и ответов и `system_fingerprint`, если Яндекс его вернул. `NOTFIVE_YANDEX_SEED` опционально задаёт целое зерно прямых запросов. Поддержка зависит от модели. Даже с одинаковым зерном облачная генерация может меняться. [Параметры Chat Completions](https://aistudio.yandex.ru/ru/docs/ai-studio/api/Chat-Completions/createChatCompletion)
