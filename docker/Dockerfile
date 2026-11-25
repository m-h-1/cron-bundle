ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && if ! php --ri mbstring > /dev/null 2>&1; then \
        apt-get install -y --no-install-recommends libonig-dev \
        && docker-php-ext-install -j"$(nproc)" mbstring; \
    fi \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
COPY composer.json phpunit.xml.dist phpcs.xml.dist phpstan.neon ./
COPY config/ config/
COPY src/ src/
COPY tests/ tests/

CMD ["sh", "-ec", "php --version && composer update --no-interaction --no-progress && composer test"]
