# PHP 8.3 CLI with the extensions CI and the app image use, plus composer —
# for running pint, phpstan and Pest on a machine with Docker but no PHP.
# Not the application image: it never serves the app.
FROM composer:2 AS composer

FROM php:8.3-cli-alpine

RUN apk add --no-cache icu-libs libzip oniguruma libpng libjpeg-turbo freetype git bash \
    && apk add --no-cache --virtual .build-deps \
        icu-dev libzip-dev oniguruma-dev libpng-dev libjpeg-turbo-dev freetype-dev $PHPIZE_DEPS \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring intl bcmath zip pcntl opcache gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

# The CLI image ships no php.ini, so PHP falls back to a 128M memory_limit;
# PHPStan's workers and Pest's failure diffs both exceed it on this codebase.
RUN echo 'memory_limit = -1' > /usr/local/etc/php/conf.d/zz-checks-memory.ini

WORKDIR /app
