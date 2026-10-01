FROM ghcr.io/roadrunner-server/roadrunner:2025.1 AS roadrunner

FROM php:8.4-cli AS base
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev librabbitmq-dev libicu-dev unzip git \
    && pecl install redis amqp \
    && docker-php-ext-enable redis amqp \
    && docker-php-ext-install pdo_pgsql intl opcache sockets \
    && rm -rf /var/lib/apt/lists/*
COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

FROM base AS app
ARG APP
WORKDIR /app
ENV APP_ENV=prod APP_DEBUG=0
COPY apps/${APP}/composer.json apps/${APP}/composer.lock apps/${APP}/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY apps/${APP}/ ./
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && php bin/console cache:warmup
CMD ["rr", "serve", "-c", ".rr.yaml"]

FROM base AS e2e
RUN apt-get update \
    && apt-get install -y --no-install-recommends docker-cli \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /e2e
COPY e2e/composer.json e2e/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist
COPY e2e/ ./
ENTRYPOINT ["vendor/bin/behat", "--no-colors", "--no-snippets", "--strict"]
