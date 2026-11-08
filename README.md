# notFive backend

Минимальное приложение: Symfony 7.4 LTS, PHP 8.4, Twig, PostgreSQL 17 и Doctrine DBAL. Главная страница — приветствие; `GET /healthz` проверяет приложение и соединение с базой (`SELECT 1`). Таблиц и пользовательских данных пока нет.

Контекст продукта: [кейс новостного дайджеста по кибербезопасности](docs/case/CONTEXT.md), [исследование моделей и архитектуры](docs/case/RESEARCH.md), [предлагаемый контракт с frontend](docs/case/FRONTEND_CONTRACT.md). Текущий отдельный [сборщик статей на Go 1.26](services/digest/README.md) ищет через Yandex и RSS/HTML, загружает тексты и проверяет даты. `POST /v1/collect` возвращает JSON статей; Python-скрипт остаётся тестовым клиентом. Дальнейшая обработка запланирована в Symfony другим разработчиком; интеграция с Symfony и Vue ещё не подключена. В README сервиса описаны настройка, запуск и HTTP-контракт, также доступна [спецификация OpenAPI](services/digest/openapi.json). Старые режимы генерации дайджеста удалены из Go.

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

Либо передать конкретный предыдущий тег/digest. Это простой деплой с коротким перерывом на перезапуск контейнера; zero-downtime он не гарантирует. Данные базы не откатываются вместе с образом. При появлении сущностей нужно добавить ORM/миграции и отдельный шаг миграций с учётом обратной совместимости.

Для production настроить резервное копирование базы вне сервера. Пример ручного бэкапа:

```sh
cd /opt/notfive
docker compose --env-file .env.production -f compose.prod.yaml exec -T database \
  sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "backup-$(date +%F-%H%M%S).dump"
```

Обычный деплой не пересоздаёт том базы. Обновление major-версии PostgreSQL требует отдельной миграции данных.

Основа конфигурации: [Symfony 7.4 LTS](https://symfony.com/releases/7.4), [деплой Symfony](https://symfony.com/doc/7.4/deployment.html), [публикация образов в GHCR из GitHub Actions](https://docs.github.com/en/actions/tutorials/publish-packages/publish-docker-images).
