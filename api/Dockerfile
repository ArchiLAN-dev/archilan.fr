FROM php:8.4-cli-alpine

# Increase upload limits for large .apworld files
RUN echo "upload_max_filesize = 200M" > /usr/local/etc/php/conf.d/uploads.ini \
    && echo "post_max_size = 200M" >> /usr/local/etc/php/conf.d/uploads.ini

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS rabbitmq-c-dev postgresql-dev icu-dev \
    && docker-php-ext-install pdo_pgsql intl \
    && docker-php-ext-enable opcache \
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
