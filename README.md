# notFive backend

Backend новостного дайджеста: Symfony 7.4 LTS, PHP 8.4, Twig, PostgreSQL 17 и Doctrine DBAL. Symfony хранит запуски, материалы, события и готовые тексты в PostgreSQL. Главная страница backend — приветствие; `GET /healthz` проверяет приложение и соединение с базой (`SELECT 1`). Публичный API находится на `POST /api/graphql`, без завершающего `/`.

Контекст продукта: [кейс новостного дайджеста по кибербезопасности](docs/case/CONTEXT.md), [исследование моделей и архитектуры](docs/case/RESEARCH.md), [предлагаемый контракт с frontend](docs/case/FRONTEND_CONTRACT.md). Отдельный [Go-сервис](services/digest/README.md) ищет через Yandex и RSS/HTML, загружает тексты и проверяет даты. Symfony вызывает закрытые GraphQL-операции Go: `collect` для сбора и `prepareDigest` для анализа и модельных текстов. Затем `DigestBuilder` сохраняет события и решения по материалам, а `DigestRenderer` оформляет `digest.md`, `post.md`, `email.md`, `journal.csv`. Содержимое файлов хранится в `artifacts`; чтение выпуска не вызывает модель. Самостоятельные REST-ручки Go описаны в [OpenAPI](services/digest/openapi.json).

## Локальный запуск

Нужны Docker и Docker Compose v2 или новее. Локальные PHP/Composer для запуска не обязательны.

```sh
docker compose up -d --build --wait
```

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

Административная мутация `startRun` импортирует файл или собирает статьи с сайтов и возвращает `runId`. Она ещё не запускает формирование выпуска автоматически. Для собранного запуска со статусом `queued` выполните:

```sh
docker compose exec -T app php bin/console digest:build RUN_UUID
```

Команда обращается к Go `prepareDigest`, который использует модель Яндекса. Результат сохраняется в БД: до пяти событий, решения журнала по материалам, четыре текста и тема письма. Сборка устанавливает `status=completed`. Для публикации и email-рассылки вызовите административную мутацию `publishDigest` или команду `digest:publish RUN_UUID`, описанные ниже. Сборка сама не публикует выпуск.

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

Для заполнения локальной БД готовыми результатами Go есть [фикстура за 8 ноября](fixtures/digest/README.md): 12 материалов, 5 событий и четыре исходных файла. `digest:load-fixture` сохраняет её как опубликованный выпуск без нового обращения к модели и без рассылки. Повторная загрузка не создаёт дубликатов. Контрольное время набора — `2026-11-08T18:26:08+03:00`, поэтому читать его нужно через `digest(date: "2026-11-08")`.

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

Первая публикация транзакционно выставляет `published_at` и сохраняет получателей в `deliveries`: только активные подтверждённые email-подписчики. Затем отправляет сохранённый `email_preview` с темой из `email_preview_details`, добавляя ссылку отписки. Модель повторно не вызывается. Язык интерфейса/подвала письма не переводит содержимое выпуска.

Повторный вызов повторяет только ожидающие и неудавшиеся отправки из исходного списка. Уже отправленные не повторяются, новые подписчики не получают старый выпуск; отписка отменяет оставшиеся доставки. Счётчики в ответе — итоговое состояние выпуска, не число писем за текущий вызов. `emailSent` означает принятие почтовым транспортом, а не подтверждённое попадание во входящие. Ошибка SMTP оставляет выпуск опубликованным, а доставку — `failed` с безопасным внутренним кодом.

В прототипе отправка выполняется синхронно внутри запроса публикации, без фонового worker. При большой базе подписчиков её следует вынести в очередь. Если процесс аварийно завершится после принятия письма SMTP, но до фиксации статуса в БД, повторная попытка может дать дубль: обычный SMTP не предоставляет атомарность с PostgreSQL.

Локально запустите Mailpit: `docker compose up -d mailpit`. Письма видны на `http://localhost:8025`. Для production задайте почтовые настройки через существующие секреты `MAILER_DSN` и `MAIL_FROM_ADDRESS` окружения GitHub `production`. В `.env.production` `DEFAULT_URI` должен быть публичным адресом backend, например `http://api.galaxiit.ru`; ссылки в письмах строятся из него. Отключённый почтовый транспорт не считается успешной отправкой. Реальные пароли/DSN не коммитить.

Фронтенд-клиент предоставляет `api.subscribeEmail(email, { locale: 'ru' })` и `api.publishDigest(runId, token)`. На главной frontend кнопка «Подписаться» открывает EmailSubscriptionDialog: email, отправка через subscribeEmail и сообщение о подтверждении по почте. Поддерживаются RU/EN, ошибки и повторная отправка.

Для проверки есть `tests/email-newsletter-smoke.py`: отдельная мигрированная БД с именем `*_smoke`, приложение на ней и изолированный Mailpit. Скрипт проверяет HTTP GraphQL, RU/EN, подтверждение, отписку, публикацию и повторы; параметры окружения перечислены в начале файла. Необязательный API_FAILURE_ROOT задаёт второй локальный экземпляр на той же тестовой БД с выключенной почтой: проверяет сохранение публикации при ошибке и успешный повтор. Использовать только тестовую БД, без реального SMTP.

## Как выпускать релизы

Схема: рабочая ветка → pull request → CI → merge в `master` → тег `v1.0.0` на нужном коммите → опубликованный GitHub Release → проверки → GitHub Container Registry (GHCR) → деплой.

- CI собирает production-образ, проверяет Composer, Symfony, Twig, HTTP-ответы и соединение с настоящей PostgreSQL.
- Release workflow принимает стабильные версии `vX.Y.Z`, проверяет, что коммит входит в `master`, и повторяет CI. Черновик, prerelease и просто push тега не запускают публикацию.
- Публикуется `ghcr.io/knigor/notfive_backend:vX.Y.Z` для `linux/amd64` и `linux/arm64`. Имя автоматически получается из `github.repository` в нижнем регистре. Теги версий не переиспользовать.
- Публикация использует встроенный `GITHUB_TOKEN` с `packages: write` только у задания `publish`. Отдельный токен реестра в GitHub Secrets не нужен. Образ связан с репозиторием через OCI label.
- Сервер получает образ по digest `@sha256:…`, то есть именно результат этой сборки. Исходники и Composer на сервере не нужны.
- После включения `DEPLOY_ENABLED=true` выполняется SSH-деплой. До этого релиз только публикует образ.

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

Fingerprint SSH-ключа сервера сверить через консоль хостинга или другое доверенное соединение. При нестандартном порте known_hosts использует запись `[host]:port`. Проверка SSH-ключа в workflow включена.

Рекомендуется защитить `master` и требовать успешный CI перед merge. Workflow не выполняется на реальном сервере, пока переменная деплоя не включена и секреты не настроены.

## Подготовка сервера

Требуется VPS/виртуальная машина с правами установки Docker. Одного SSH на shared-хостинге может быть недостаточно. Понадобятся Docker Engine, Compose plugin, Nginx и `flock` (пакет util-linux). Для Ubuntu: [официальная инструкция Docker](https://docs.docker.com/engine/install/ubuntu/); для другой ОС выбрать её инструкцию.

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

5. В `deploy/nginx.conf` заменить `example.com` своим доменом, добавить конфигурацию в Nginx, проверить `sudo nginx -t` и перезагрузить Nginx. Настроить DNS и HTTPS-сертификат, например через Certbot. Пример содержит только HTTP: TLS нужно настроить до публичного использования. При изменении `HTTP_PORT` также изменить порт в Nginx.

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
