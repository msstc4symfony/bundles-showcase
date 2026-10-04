FROM ghcr.io/roadrunner-server/roadrunner:2025.1.15 AS roadrunner
FROM mlocati/php-extension-installer:2.12 AS extension-installer
FROM composer:2.10.3 AS composer

FROM php:8.4.26-cli AS runtime
COPY --from=extension-installer /usr/bin/install-php-extensions /usr/local/bin/
# The installer removes build-only packages (-dev headers, compilers) after compiling.
RUN install-php-extensions redis-6.3.0 amqp-2.2.0 pdo_pgsql intl opcache sockets \
    && rm /usr/local/bin/install-php-extensions
RUN printf 'date.timezone=UTC\nopcache.enable_cli=1\n' > /usr/local/etc/php/conf.d/showcase.ini
COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

FROM runtime AS tools
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/*

FROM tools AS build
ARG APP
WORKDIR /app
ENV APP_ENV=prod APP_DEBUG=0
COPY apps/${APP}/composer.json apps/${APP}/composer.lock apps/${APP}/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY apps/${APP}/ ./
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && php bin/console cache:warmup

FROM runtime AS app
WORKDIR /app
ENV APP_ENV=prod APP_DEBUG=0
# Code stays root-owned; the worker may write only its cache and logs.
COPY --from=build /app /app
COPY --from=build --chown=www-data:www-data /app/var /app/var
USER www-data
CMD ["rr", "serve", "-c", ".rr.yaml"]

# Stays root: the @chaos scenarios drive the docker socket.
FROM tools AS e2e
RUN apt-get update \
    && apt-get install -y --no-install-recommends docker-cli \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /e2e
COPY e2e/composer.json e2e/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist
COPY e2e/ ./
ENTRYPOINT ["vendor/bin/behat", "--no-colors", "--no-snippets", "--strict"]
