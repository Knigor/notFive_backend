# syntax=docker/dockerfile:1
FROM php:8.4-apache-bookworm AS base

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl opcache zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

FROM base AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_CACHE_DIR=/tmp/composer-cache
COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,id=notfive-composer,target=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress

FROM base AS production
ENV APP_ENV=prod APP_DEBUG=0
COPY --from=dependencies /app/vendor ./vendor
COPY .env composer.json composer.lock symfony.lock ./
COPY bin ./bin
COPY config ./config
COPY public ./public
COPY src ./src
COPY migrations ./migrations
COPY schema.graphql ./schema.graphql
COPY templates ./templates
    COPY translations ./translations
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/app-entrypoint
COPY --chmod=755 docker/migrate.sh /usr/local/bin/app-migrate
RUN mkdir -p var/datasets && chown -R www-data:www-data var

HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r '$r = @file_get_contents("http://127.0.0.1/healthz", false, stream_context_create(["http" => ["timeout" => 3]])); exit($r !== false && json_decode($r, true) === ["status" => "ok"] ? 0 : 1);'

ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
