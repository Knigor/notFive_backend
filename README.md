# notFive backend

Backend новостного дайджеста: Symfony 7.4 LTS, PHP 8.4, Twig, PostgreSQL 17 и Doctrine DBAL. Symfony хранит запуски, материалы, события и готовые тексты в PostgreSQL. Главная страница backend — приветствие; `GET /healthz` проверяет приложение и соединение с базой (`SELECT 1`). Публичный API находится на `POST /api/graphql`, без завершающего `/`.

Контекст продукта: [кейс новостного дайджеста по кибербезопасности](docs/case/CONTEXT.md), [исследование моделей и архитектуры](docs/case/RESEARCH.md), [предлагаемый контракт с frontend](docs/case/FRONTEND_CONTRACT.md). Отдельный [Go-сервис](services/digest/README.md) ищет через Yandex и RSS/HTML, загружает тексты и проверяет даты. Symfony вызывает закрытые GraphQL-операции Go: `collect` для сбора и `prepareDigest` для анализа и модельных текстов. Затем `DigestBuilder` сохраняет события и решения по материалам, а `DigestRenderer` оформляет `digest.md`, `post.md`, `email.md`, `journal.csv`. Содержимое файлов хранится в `artifacts`; чтение выпуска не вызывает модель. Самостоятельные REST-ручки Go описаны в [OpenAPI](services/digest/openapi.json).

## Локальный запуск

Нужны Docker и Docker Compose v2 или новее. Локальные PHP/Composer для запуска не обязательны.

```sh
docker compose up -d --build --wait
```

При запуске контейнер применяет миграции и загружает демовыпуск. Для изолированной
проверки на пустой БД можно запустить Compose с `APP_AUTO_MIGRATE=0` и применить
миграции вручную.

Открыть http://localhost:8080. Если порт занят: `HTTP_PORT=8081 docker compose up -d --build --wait`.

```sh
docker compose logs -f app
docker compose exec app php bin/console about
sh scripts/smoke.sh
docker compose down
```

Исходники, шаблоны, конфигурация и public подключены в контейнер: правки видны без сборки. При изменении зависимостей или Dockerfile повторить сборку. Для установки зависимостей на компьютере нужны PHP 8.4+, расширение `pdo_pgsql` и Composer: `composer install`.

База доступна внутри Compose по имени `database`, её порт наружу не публикуется. Данные остаются в томе `notfive-dev_database_data` после `docker compose down`. Команда `down --volumes` удаляет данные — для обычной остановки её не использовать.

## Миграции после обновления backend

После получения нового кода проверьте и примените миграции из `migrations/`:

```sh
docker compose exec -T app php bin/console doctrine:migrations:status
docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction
```

Миграция `Version20261108192750` добавляет `events.group_key`, `events.post_text` и `materials.journal_group`, необходимые для сохранения и экспорта структурированного выпуска. Для чтения дайджеста отдельная новая таблица не нужна.

На сервере команды выполняются в каталоге с актуальным `compose.prod.yaml` и `.env.production`, после обновления образа приложения:

```sh
cd /opt/notfive
docker compose --env-file .env.production -f compose.prod.yaml exec -T app \
  php bin/console doctrine:migrations:status
docker compose --env-file .env.production -f compose.prod.yaml exec -T app \
  php bin/console doctrine:migrations:migrate --no-interaction
```

## Формирование и чтение дайджеста

Для сбора через `startRun` можно выбрать 1–5 источников каталога со статусом `candidate` или `ready`; предварительно менять статус источника не нужно. Источники `disabled` и неизвестные ID не принимаются.

Административная мутация `startRun` импортирует файл или собирает статьи с сайтов и возвращает `runId`. Она ещё не запускает формирование выпуска автоматически. Для собранного запуска со статусом `queued` выполните:

```sh
docker compose exec -T app php bin/console digest:build RUN_UUID
```

Команда обращается к Go `prepareDigest`, который использует модель Яндекса. Результат сохраняется в БД: до пяти событий, решения журнала по материалам, четыре текста и тема письма. Сборка устанавливает `status=completed`. Для публикации и рассылки вызовите административную мутацию `publishDigest` или команду `digest:publish RUN_UUID`, описанные ниже. Сборка сама не публикует выпуск.

Фронтенд читает выпуск через `digest(date: String!): Digest` на том же `POST /api/graphql`, без JWT:

```graphql
query Digest($date: String!) {
  digest(date: $date) {
    runId
    controlTime
    periodStart
    timezone
    language
    publishedAt
    totalMaterials
    events {
      id
      rank
      title
      summary
      consequences
      selectionReason
      sources { id name url publishedAt }
    }
    files { digest post email csv emailSubject }
  }
}
```

Пример переменных: `{"date":"2026-11-08"}`. `date` — календарная дата контрольного времени в Москве, строго `YYYY-MM-DD`. Из запусков с `control_time` в интервале `[00:00 выбранного дня, 00:00 следующего дня)` выбирается один последний опубликованный выпуск со статусом `completed`. Его новостное окно остаётся `(controlTime−24h, controlTime]`, а не календарными сутками. `events` содержит максимум пять событий в порядке `rank`.

Если опубликованного выпуска за день нет, ответ содержит `"digest": null`. Неверная дата возвращает GraphQL-ошибку на языке `Accept-Language` (`ru` или `en`, запасной `ru`). Язык самого выпуска указан в `language`: русский сохранённый текст не переводится при чтении.

`files.digest`, `files.post`, `files.email` и `files.csv` — сохранённое содержимое соответствующих файлов; `emailSubject` — тема письма. `files` и его поля nullable: у старого выпуска отдельный артефакт может отсутствовать. Строки возвращаются без повторной генерации, форматирования и записи на диск. Фронтенд может показать их или сохранить как `digest.md`, `post.md`, `email.md` и `journal.csv`. При отображении текста следует сохранять переносы строк и не исполнять HTML из входных публикаций.

Актуальная схема находится в [schema.graphql](schema.graphql). Публичное чтение не изменяет выпуск, не публикует черновики и не отправляет сообщения.

Для списка доступных выпусков фронтенд вызывает `{ publishedDigestDates }`: возвращаются уникальные даты `YYYY-MM-DD` по московскому контрольному времени, в порядке от новых к старым. Учитываются только `completed` с заполненным `published_at`. Затем главная получает `digest(date)` для первой даты или выбранного пользователем дня. Если опубликованных выпусков нет, список пустой. Учебные новости больше не подставляются вместо ответа API.

Для заполнения локальной БД готовыми результатами Go есть [фикстура за 8 ноября](fixtures/digest/README.md): 12 материалов, 5 событий и четыре исходных файла. `doctrine:fixtures:load --group=demo-digest --append` сохраняет её как опубликованный выпуск без нового обращения к модели и без рассылки. Повторная загрузка не создаёт дубликатов. Контрольное время набора — `2026-11-08T18:26:08+03:00`, поэтому читать его нужно через `digest(date: "2026-11-08")`.

## Email-подписка и публикация

После обновления образа примените миграции: `Version20261108210000` добавляет поля подтверждения/отписки, ограничение отправок и статус доставки `cancelled`.

Кнопка в модальном окне отправляет на `POST /api/graphql` без JWT:

```graphql
mutation SubscribeEmail($email: String!) {
  subscribeEmail(email: $email) { status message }
}
```

Переменные: `{"email":"reader@example.com"}`. Заголовок `Accept-Language: ru` или `en` выбирает язык ответа и письма подтверждения. `status: "accepted"` означает, что запрос принят; адрес ещё должен быть подтверждён. Для уже активного адреса и повторов возвращается тот же ответ. Фронтенд показывает `message`, не объявляя подписку активной до подтверждения.

Пользователь получает ссылку на backend `/email/confirm?token=…`, открывает страницу и нажимает кнопку подтверждения. Ссылка действует 24 часа; простой GET ничего не меняет. В каждом выпуске есть ссылка `/email/unsubscribe?token=…`; отписка также применяется только после нажатия кнопки (POST). Страницы работают без отдельной реализации на фронтенде. Повторное подтверждение на адрес отправляется не чаще раза в минуту; с одного IP — не более пяти попыток за десять минут. Повторная подписка после отписки требует нового подтверждения.

Администратор публикует готовый выпуск с `Authorization: Bearer <JWT>`:

```graphql
mutation PublishDigest($runId: ID!) {
  publishDigest(runId: $runId) {
    runId
    publishedAt
    alreadyPublished
    emailSent
    emailFailed
    emailPending
  }
}
```

Передайте UUID запуска из `startRun`, после успешного `digest:build`. Нужны `status=completed`, четыре артефакта и тема письма. Альтернатива из CLI:

```sh
docker compose exec -T app php bin/console digest:publish RUN_UUID
```

Первая публикация транзакционно выставляет `published_at` и фиксирует в `deliveries` активных подписчиков email и Telegram. Оба канала читают сохранённый выпуск. Email добавляет ссылку отписки, а Telegram отправляет события отдельными сообщениями. Сервис приложения использует существующий `TELEGRAM_BOT_TOKEN`. При пустом токене доставки остаются в очереди. Модель повторно не вызывается. Язык интерфейса/подвала письма не переводит содержимое выпуска.

Повторный вызов повторяет только ожидающие и неудавшиеся отправки из исходного списка. Уже отправленные не повторяются, новые подписчики не получают старый выпуск; отписка отменяет оставшиеся доставки. Счётчики в ответе — итоговое состояние выпуска, не число писем за текущий вызов. `emailSent` означает принятие почтовым транспортом, а не подтверждённое попадание во входящие. Ошибка SMTP оставляет выпуск опубликованным, а доставку — `failed` с безопасным внутренним кодом.

В прототипе отправка синхронна. Для повтора только Telegram-доставок используйте `telegram:send-digest RUN_UUID`. Без UUID команда берёт последний опубликованный выпуск. Пауза отменяет незавершённые доставки, а повтор Telegram продолжает с первой непринятой части. Если провайдер принял письмо или часть Telegram до сбоя при записи в PostgreSQL, повтор может продублировать эту отправку.

Локально запустите Mailpit: `docker compose up -d mailpit`. Письма видны на `http://localhost:8025`. Для production задайте почтовые настройки через существующие секреты `MAILER_DSN` и `MAIL_FROM_ADDRESS` окружения GitHub `production`. Публичный адрес backend в `DEFAULT_URI` — `https://api.galaxiit.ru`; он задан также в локальном `.env` по запросу команды и передаётся в Docker Compose. Ссылки подтверждения и отписки строятся из него. Для полностью локальной проверки писем можно запустить `DEFAULT_URI=http://localhost:8080 docker compose up -d app`; при запуске PHP без Docker используйте `.env.local`. Отключённый почтовый транспорт не считается успешной отправкой. Реальные пароли/DSN не коммитить.

Фронтенд-клиент предоставляет `api.subscribeEmail(email, { locale: 'ru' })` и `api.publishDigest(runId, token)`. На главной frontend кнопка «Подписаться» открывает EmailSubscriptionDialog: email, отправка через subscribeEmail и сообщение о подтверждении по почте. Поддерживаются RU/EN, ошибки и повторная отправка.

Для проверки есть `tests/email-newsletter-smoke.py`: отдельная мигрированная БД с именем `*_smoke`, приложение на ней и изолированный Mailpit. Скрипт проверяет HTTP GraphQL, RU/EN, подтверждение, отписку, публикацию и повторы; параметры окружения перечислены в начале файла. Необязательный API_FAILURE_ROOT задаёт второй локальный экземпляр на той же тестовой БД с выключенной почтой: проверяет сохранение публикации при ошибке и успешный повтор. Использовать только тестовую БД, без реального SMTP.

SMTP/SMTPS использует отдельный таймаут 5 секунд на сетевую операцию через `SmtpTimeoutTransportFactory`. Таймауты HTTP-вызовов Go не меняются. Подтверждение подписки пока отправляется синхронно: при ошибке транспорта API возвращает переведённую GraphQL-ошибку, освобождает резерв подтверждения и оставляет адрес неподтверждённым. Это не общий дедлайн письма и не очередь отправки. Недоступность SMTP с VPS нужно устранять отдельно в сетевых настройках или конфигурации почтового провайдера.

Регрессия зависшего SMTP — `python3 -B tests/email-smtp-timeout-smoke.py`: то же изолированное окружение, но `API_FAILURE_ROOT` должен указывать на приложение с SMTP-заглушкой, принимающей TCP без приветствия. Проверяются ограниченное ожидание, ошибки RU/EN, очистка резерва и успешный повтор через Mailpit. Реальные письма не отправляются.

## Как выпускать релизы

Схема: рабочая ветка → pull request → CI → merge в `master` → тег `v1.0.0` на нужном коммите → опубликованный GitHub Release → проверки → GitHub Container Registry (GHCR) → деплой.

- CI собирает production-образ, проверяет Composer, Symfony, Twig, HTTP-ответы и соединение с настоящей PostgreSQL.
- Release workflow принимает стабильные версии `vX.Y.Z`, проверяет, что коммит входит в `master`, и повторяет CI. Черновик, prerelease и просто push тега не запускают публикацию.
- Публикуются `ghcr.io/knigor/notfive_backend:vX.Y.Z` и `ghcr.io/knigor/notfive-digest:vX.Y.Z` только для `linux/amd64`, под архитектуру текущего VPS `x86_64`. Эмуляция ARM в релизе не используется. Для локальной работы на Mac можно собрать образ через Compose. Имена автоматически приводятся к нижнему регистру. Теги версий не переиспользовать.
- Release вызывает проверку и публикацию Go через reusable workflow `digest.yml`. Отдельный запуск Digest service на событие release не нужен: общий deploy ждёт готовности обоих образов.
- Публикация использует встроенный `GITHUB_TOKEN` с `packages: write` только у заданий публикации. Отдельный токен реестра в GitHub Secrets не нужен. Образы связаны с репозиторием через OCI label.
- Сервер получает оба образа по digest `@sha256:…`, то есть именно результаты этой сборки. Исходники и Composer на сервере не нужны.
- После включения `DEPLOY_ENABLED=true` выполняется SSH-деплой: конфигурация из Environment `production`, Go и его сеть, Symfony, миграции, обработчик запусков и Telegram. До этого релиз только публикует образы.

Репозиторий: `Knigor/notFive_backend`, ветка `master`. Перед выпуском загрузить изменения workflow и приложения на GitHub; в коммит должны попасть `composer.lock`, `symfony.lock` и `.github/workflows`. Секреты из `.env.production` и локальные `vendor/var` исключены через `.gitignore`.

### Настройки GitHub

В Settings → Secrets and variables → Actions:

| Тип | Имя | Значение |
| --- | --- | --- |
| Repository variable | `DEPLOY_ENABLED` | `true` только после подготовки сервера |

Переменные `DOCKERHUB_USERNAME` и `DOCKERHUB_TOKEN` больше не используются. Не создавать свой secret `GITHUB_TOKEN`: GitHub выдаёт его автоматически на время запуска.

Для первого выпуска оставить `DEPLOY_ENABLED=false`, опубликовать Release с новым тегом `vX.Y.Z` на `master` и дождаться успешного `publish` в Actions. Образ появится в Packages аккаунта/репозитория. Новый пакет по умолчанию приватный, даже если репозиторий публичный. Для приватного пакета настроить вход на VPS, как описано ниже; если образ решено открыть всем, видимость меняется отдельно в Package settings. Публичный образ включает код приложения.

Если пакет с таким именем уже существовал и публикация получает `permission_denied`, проверить Package settings → Manage Actions access: у этого репозитория должно быть право записи. Политика организации также должна разрешать публикацию пакетов.

Для деплоя создать Environment `production` и добавить в него secrets:

| Имя | Значение |
| --- | --- |
| `DEPLOY_HOST` | IP или DNS-имя сервера |
| `DEPLOY_USER` | SSH-пользователь с правом запуска Docker и записи в `/opt/notfive` |
| `DEPLOY_PORT` | SSH-порт; по умолчанию 22 |
| `SSH_PRIVATE_KEY` | Приватный ключ для деплоя; публичный добавить в `authorized_keys` сервера |
| `SSH_KNOWN_HOSTS` | Проверенная запись ключа сервера в формате known_hosts |
| `APP_SECRET` | Секрет Symfony; сохранить существующее production-значение |
| `JWT_SECRET` | Отдельный секрет JWT, 64 шестнадцатеричных символа в нижнем регистре |
| `ADMIN_BOOTSTRAP_USERNAME` | Логин администратора: латинская буква, затем буквы, цифры или `_`; всего 3–64 символа |
| `ADMIN_BOOTSTRAP_PASSWORD` | Пароль администратора, 12–128 символов из `A-Za-z0-9_@.:-` |
| `POSTGRES_PASSWORD` | Текущий пароль роли PostgreSQL; переносить без изменения |
| `MAILER_DSN` | Production-транспорт SMTP; локальный Mailpit и отключённый транспорт не подходят |
| `MAIL_FROM_ADDRESS` | Разрешённый SMTP-провайдером адрес отправителя |
| `TELEGRAM_BOT_TOKEN` | Действующий токен BotFather |
| `DIGEST_SERVICE_TOKEN` | Общий серверный токен Symfony и Go, минимум 32 символа |
| `YANDEX_API_KEY` | Ключ Yandex AI Studio для поиска и генерации |

В том же Environment `production` добавить variables:

| Имя | Значение или default workflow |
| --- | --- |
| `DEFAULT_URI` | Обязательно: публичный адрес backend для ссылок в письмах |
| `APP_CORS_ORIGIN` | Обязательно: разрешённый origin frontend |
| `YANDEX_FOLDER_ID` | Обязательно: каталог Yandex Cloud |
| `POSTGRES_DB`, `POSTGRES_USER` | Текущие production-значения; default обоих — `notfive` |
| `HTTP_PORT` | Порт Symfony на loopback хоста; default `8080`, должен совпадать с Nginx |
| `GO_SERVICE_URL` | `http://digest:8081`; Go доступен Symfony по Docker-сети |
| `DIGEST_HTTP_PORT` | Порт Go на loopback хоста; default `8081` |
| `YANDEX_MODEL` | `yandexgpt-5.1` |
| `YANDEX_ANALYSIS_MODEL`, `YANDEX_EXPORT_MODEL` | `aliceai-llm-flash` |
| `YANDEX_ANALYSIS_MAX_OUTPUT_TOKENS` | `12000` |
| `YANDEX_EXPORT_MAX_OUTPUT_TOKENS`, `MAX_OUTPUT_TOKENS` | `8000` |
| `YANDEX_BASE_URL` | `https://ai.api.cloud.yandex.net/v1` |
| `YANDEX_SEARCH_DOMAINS` | `securitylab.ru,xakep.ru,habr.com,ptsecurity.com,kaspersky.ru` |
| `REQUEST_TIMEOUT` | `180s` |
| `MAX_CONCURRENT` | `2` |

`DEPLOY_ENABLED` остаётся именно **Repository variable**, потому что условие задания проверяется до загрузки Environment. `APP_IMAGE` и `DIGEST_IMAGE` вручную не задаются: deploy получает их из результатов публикации. Все значения конфигурации передаются на VPS JSON через SSH stdin, проверяются и сохраняются в `/opt/notfive/.env.production` с правами `0600`; содержимое секретов не попадает в аргументы команд. Этот env используется обоими Compose-проектами. При переносе существующей конфигурации сначала сохранить текущие production-пароли, а затем включать новый релиз.

Fingerprint SSH-ключа сервера сверить через консоль хостинга или другое доверенное соединение. При нестандартном порте known_hosts использует запись `[host]:port`. Проверка SSH-ключа в workflow включена.

Рекомендуется защитить `master` и требовать успешный CI перед merge. Workflow не выполняется на реальном сервере, пока переменная деплоя не включена и секреты не настроены.

## Подготовка сервера

Требуется VPS/виртуальная машина с правами установки Docker. Одного SSH на shared-хостинге может быть недостаточно. Понадобятся Docker Engine, Compose plugin, Nginx, Python 3 и `flock` (пакет util-linux). Для Ubuntu: [официальная инструкция Docker](https://docs.docker.com/engine/install/ubuntu/); для другой ОС выбрать её инструкцию.

Запросы идут так:

```text
Интернет → Nginx на сервере (HTTPS)
         → 127.0.0.1:8080 → контейнер приложения (Apache + PHP + Symfony)
                         → контейнер PostgreSQL → постоянный Docker volume
```

Apache внутри образа обслуживает PHP по HTTP. Поэтому Nginx на сервере достаточно `proxy_pass`; исходники и PHP-FPM на хосте не нужны. Порт приложения слушает только loopback, порт PostgreSQL не публикуется. В production Symfony доверяет proxy-заголовкам из приватной сети; сохранять эту схему закрытого доступа к контейнеру.

1. Создать каталог и отдать его пользователю деплоя (заменить `deploy` на своего пользователя):

   ```sh
   sudo install -d -o deploy -g deploy -m 750 /opt/notfive
   ```

2. Скопировать туда `compose.prod.yaml`, `.env.production.example` и каталог `deploy/`. При автодеплое workflow обновляет Compose и скрипт из выпускаемого релиза. `.env.production` хранится только на сервере:

   ```sh
   cd /opt/notfive
   cp .env.production.example .env.production
   chmod 600 .env.production
   openssl rand -hex 32
   openssl rand -hex 32
   ```

   Вписать результаты как разные значения `APP_SECRET` и `POSTGRES_PASSWORD`, указать свой `APP_IMAGE`, домен в `DEFAULT_URI`. Примеры паролей обязательно заменить перед первым запуском. После инициализации PostgreSQL простая правка `POSTGRES_PASSWORD` не меняет пароль внутри базы — потребуется `ALTER ROLE`.

3. Для приватного пакета создать GitHub Personal access token **classic** с правом `read:packages`; владелец токена должен иметь доступ к пакету. При необходимости организации авторизовать токен для SSO. На VPS выполнить вход **от того же пользователя, которого использует SSH-деплой**:

   ```sh
   docker login ghcr.io --username Knigor
   ```

   В интерактивный запрос Password вставить токен, не пароль GitHub. Токен не записывать в `.env.production`, код или историю команд. Для публичного пакета вход не требуется. `GITHUB_TOKEN` workflow не предназначен для постоянного входа на VPS. [Аутентификация и видимость GHCR](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry).

4. После публикации первого образа запустить:

   ```sh
   cd /opt/notfive
   sh deploy/deploy.sh ghcr.io/knigor/notfive_backend:v1.0.0
   curl --fail http://127.0.0.1:8080/healthz
   ```

5. Для нового сервера направить DNS A-запись `api.galaxiit.ru` на IP сервера и открыть порты 80/443. Установить `deploy/nginx.conf` в Nginx, проверить `sudo nginx -t` и перезагрузить Nginx. Это начальная HTTP-конфигурация для выпуска сертификата; при другом домене или `HTTP_PORT` изменить соответствующие значения. Затем включить HTTPS:

   ```sh
   sudo apt-get update
   sudo apt-get install -y certbot python3-certbot-nginx
   sudo certbot --nginx --redirect -d api.galaxiit.ru
   sudo nginx -t
   sudo certbot renew --dry-run
   systemctl list-timers certbot.timer
   curl --fail https://api.galaxiit.ru/healthz
   ```

   Certbot добавляет TLS и перенаправление HTTP → HTTPS в конфигурацию сервера. После этого не заменяйте её начальным `deploy/nginx.conf`; релиз приложения эту конфигурацию не перезаписывает. На действующем VPS HTTPS уже настроен, повторять установку не нужно. В GitHub Environment `production` backend задайте `DEFAULT_URI=https://api.galaxiit.ru`, в Environment `github-pages` frontend — `URL_BACKEND=https://api.galaxiit.ru/`. Локальные `.env` используют тот же публичный адрес. Внутренние `http://127.0.0.1:8080` и `http://digest:8081` остаются HTTP. Браузер обращается к API непосредственно по HTTPS: CORS не снимает блокировку Mixed Content.

   На сервере с уже настроенным TLS перенесите блоки `map` и `log_format`
   из `deploy/nginx.conf` в контекст `http`, затем задайте
   `access_log /var/log/nginx/notfive-access.log notfive_access;` в блоках
   `server` для этого домена. Так токены из ссылок подтверждения и отписки
   не попадут в access log. Проверьте конфигурацию через `sudo nginx -t`
   перед перезагрузкой.

6. Добавить GitHub secrets и включить `DEPLOY_ENABLED=true`. Следующий опубликованный релиз выполнит деплой автоматически.

## Обновления и откат

Скрипт скачивает образ, запускает Compose и ждёт healthy-состояния приложения и базы. После успеха записывает digest/тег в `.env.production`; прошлый образ сохраняет в `.image.previous`. Если запуск не удался, скрипт пытается вернуть предыдущий образ и завершает workflow с ошибкой. Для первого запуска предыдущего образа нет.

Ручной откат после успешного релиза:

```sh
cd /opt/notfive
sh deploy/deploy.sh "$(cat .image.previous)"
```

Либо передать конкретный предыдущий тег/digest. Это простой деплой с коротким перерывом на перезапуск контейнера; zero-downtime он не гарантирует. Данные базы и применённые миграции не откатываются вместе с образом; совместимость предыдущего образа со схемой БД нужно учитывать отдельно.

Для production настроить резервное копирование базы вне сервера. Пример ручного бэкапа:

```sh
cd /opt/notfive
docker compose --env-file .env.production -f compose.prod.yaml exec -T database \
  sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "backup-$(date +%F-%H%M%S).dump"
```

Обычный деплой не пересоздаёт том базы. Обновление major-версии PostgreSQL требует отдельной миграции данных.

Основа конфигурации: [Symfony 7.4 LTS](https://symfony.com/releases/7.4), [деплой Symfony](https://symfony.com/doc/7.4/deployment.html), [публикация образов в GHCR из GitHub Actions](https://docs.github.com/en/actions/tutorials/publish-packages/publish-docker-images).


## Production-почта через локальный компьютер

При `EMAIL_DELIVERY_MODE=queued` подписка и публикация только сохраняют задания в PostgreSQL на VPS. Браузер продолжает работать с `https://api.galaxiit.ru/api/graphql`; ссылки подтверждения и отписки тоже ведут на production. Локальный backend и его Mailpit не заменяют production API.

Symfony Messenger использует очереди `email` и `email_failed` в таблице `messenger_messages`. SMTP вызывается только отдельным consumer. В заданиях подтверждения лежат ID адресата и запроса: ссылка воспроизводится через HMAC с production `APP_SECRET`, исходный токен не сохраняется. В `deliveries` статус `sent` ставится после принятия письма SMTP. Отписка, истёкшая ссылка, новое подтверждение и уже выполненное задание проверяются перед отправкой. При ошибке идут восемь повторов с задержкой; окончательные ошибки остаются в `email_failed`.

В GitHub Environment `production` задайте Variable `EMAIL_DELIVERY_MODE=queued` и выпустите релиз. Миграции создают очередь. SMTP-настройки остаются в существующих production Secrets и не записываются в Git.

Для компьютера с Python 3, Docker и уже настроенным SSH-доступом:

```bash
python3 scripts/email-worker.py --check
python3 scripts/email-worker.py
```

Обработчик получает необходимые настройки и точный образ приложения с VPS, открывает SSH-туннель к production PostgreSQL только на loopback компьютера и запускает локальный контейнер. Открывать входящие порты на компьютере или публиковать PostgreSQL не нужно. SSH-ключ остаётся на компьютере, host key обязательно проверяется. Значения секретов не выводятся и не передаются аргументами процессов; временный env-файл имеет права `0600` и удаляется при завершении.

Первый запуск может скачать образ. Проверка `--check` проверяет БД и SMTP-авторизацию, но не отправляет письма. Обычный запуск обрабатывает реальные задания пользователей production. Терминал нужно оставить открытым; Ctrl+C останавливает обработчик. При остановке/сне компьютера задания ждут на VPS. Просроченные подтверждения не отправляются: пользователь запрашивает новое на сайте. Между рабочими циклами (обычно раз в минуту) обработчик перечитывает production-настройки и при необходимости загружает новый образ.

SMTP и транзакция БД не атомарны: авария после приёма письма SMTP, но до фиксации `sent` может привести к повтору. Обычные повторы задания и повторная публикация уже отправленные письма не дублируют.

Проверка очереди выполняется `tests/email-queue-smoke.py` на отдельной БД `*_smoke` и Mailpit: HTTP быстро принимает запрос, до consumer письма нет, после него подтверждение и рассылка отправлены; повтор, отписка и SMTP-сбой проверяются отдельно. Локальный стандартный режим `sync` с Mailpit сохранён.

## Диагностика обработки материалов

Административный `runStatus(runId)` возвращает `diagnostics`: оба Go run ID,
счётчики, предупреждения и решения по материалам (`stage`, `status`, `reason`,
`error`). Список объединяет загруженные статьи, ошибки импорта, отклонённые ссылки
и не обработанные из-за лимитов кандидаты. Счётчик `candidates` означает учтённые
материалы этого запуска, а не все публикации на выбранных сайтах.

Новый `journal.csv` строится из этих решений и сохраняет пять колонок ТЗ:
`id;status;group;reason;error`. Исторические артефакты не меняются; для старого
запуска экран диагностики может восстановить дополнительные отказы из
`settings_snapshot.go_collection.researchJson`. Стадии Symfony пишутся в канал
`digest_run` на stderr с ID запуска и Go collection/preparation ID.

Административный `runStatistics(runId: ID!): RunStatistics` на `/api/graphql`
даёт данные для графиков админки. Нужен обычный `Authorization: Bearer` JWT
администратора. Несуществующий или некорректный UUID возвращает `null`.

```graphql
query RunStatistics($runId: ID!) {
  runStatistics(runId: $runId) {
    runId status inputMode queueDurationMs
    counts { candidates collected imported included merged excluded events }
    exclusions { code stage site count }
    calls { stage attempt model durationMs inputTokens outputTokens }
    discoverySources {
      url site kind attempted found inspectedLinks uninspectedLinks outsideWindow error
    }
    evidence { stage available exact normalized notFound other }
    search { modelAttempts webSearchCalls traceAvailable }
    warnings
  }
}
```

Метрики берутся из БД и сохранённого Go-аудита через защищённый `GET /v1/runs/{id}`.
Чтение не запускает поиск, модель, публикацию или рассылку. Промпты, тексты статей
и исходная трасса провайдера в ответ не включаются. При недоступном аудите остаются
счётчики из БД и сохранённые `model_calls`; отсутствующие показатели возвращаются
как `null`/`available: false` с предупреждением.

`durationMs` — время вызова модели, а не всего этапа с загрузкой и сохранением.
`queueDurationMs` — разность `started_at` и `created_at`. Входные и выходные токены
показываются отдельно: провайдер может учитывать токены инструментов дополнительно.
Вызовы Web Search считаются только по доступной трассе, это не счёт биллинга.
Проверки цитат анализа и экспорта — отдельные серии, не число уникальных фактов.
`feed` обозначает RSS/Atom, `html` — разбор раздела сайта; `attempted: false`
означает плановый источник без сохранённой попытки. В файловом сценарии этап
сбора пропущен. Счётчик событий имеет другую единицу, чем счётчики материалов.
