FROM php:8.5-cli-alpine

# Les paquets Alpine de l'image de base vieillissent avec le tag php auquel elle est épinglée.
# `apk upgrade` récupère les correctifs de sécurité publiés depuis. Sans lui, le worker est resté
# sur postgresql-libs 18.4-r0 et le gate Trivy a bloqué la publication de la v0.16.1 sur la vague
# de CVE PostgreSQL 18.5 (36 HIGH, toutes corrigées en amont).
#
# Attention : cette ligne ne se rejoue que si la couche est reconstruite. Le workflow de
# publication met les couches en cache (`cache-from: type=gha,scope=api-worker`), donc un
# Dockerfile inchangé pendant des mois continue de servir une couche apk figée. Quand une CVE
# corrigée apparaît sans changement de code, forcer la reconstruction en purgeant le scope de
# cache buildx (aucun run en vol) ou en touchant cette ligne.
RUN apk upgrade --no-cache

# Increase upload limits for large .apworld files
RUN echo "upload_max_filesize = 200M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 200M" >> /usr/local/etc/php/conf.d/uploads.ini

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS rabbitmq-c-dev postgresql-dev icu-dev \
    && docker-php-ext-install pdo_pgsql intl \
    && pecl install amqp \
    && docker-php-ext-enable amqp \
    && apk del .build-deps \
    && apk add --no-cache git rabbitmq-c postgresql-libs icu-libs

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN --mount=type=secret,id=composer_token \
    COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$(cat /run/secrets/composer_token)\"}}" \
    composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

COPY . .
RUN composer run-script post-install-cmd --no-interaction 2>/dev/null || true \
    && php bin/console cache:warmup --env=prod

CMD ["php", "bin/console", "messenger:consume", "async", "scheduler_default", \
     "--time-limit=3600", "--memory-limit=128M", "-vv"]
