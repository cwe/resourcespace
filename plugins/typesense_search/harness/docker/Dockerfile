# PHP CLI for the harness and the code checks: the extensions core's composer.json requires, sqlite3 for the
# harness database, Composer, and phpcs installed globally. Built by run.ps1 / run.sh / check.ps1 on first use.
FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" intl zip gd mysqli pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_HOME=/usr/local/composer \
    COMPOSER_CACHE_DIR=/composer-cache \
    COMPOSER_ALLOW_SUPERUSER=1 \
    PATH="/usr/local/composer/vendor/bin:${PATH}"

RUN composer global require --no-interaction squizlabs/php_codesniffer \
    && rm -rf /composer-cache

WORKDIR /app/plugins/typesense_search/harness
