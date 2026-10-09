# syntax=docker/dockerfile:1

# ---- assets ----------------------------------------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources resources
COPY vite.config.ts tsconfig.json ./
COPY public public
RUN npm run build

# ---- vendor ------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./

# The `composer_auth` secret is optional and carries a COMPOSER_AUTH JSON
# blob. Composer fetches dists from codeload.github.com, which rate-limits
# *anonymous* requests per IP — and CI runners share addresses, so an
# unauthenticated build is a coin flip: on one run every retry across
# twenty minutes lost to HTTP 429, none of it for a reason in this
# repository. A token raises the limit enough that the build stops being a
# lottery.
#
# A build *secret*, not a build ARG: an ARG's value is recorded in the
# image's build history, so the token would outlive the build inside the
# image. This mount exists only for the life of the RUN.
#
# Optional by design (`required=false` is the default, hence the -f test):
# a developer running `docker compose up --build` from a clean checkout has
# no token and must not need one. They just get the anonymous limit, which
# is fine for one machine making one build.
#
# `--ignore-platform-req=ext-gd` (W74): phpoffice/phpspreadsheet and
# phpoffice/phpword require `ext-gd`, the `composer:2` image does not have it,
# and this stage would otherwise refuse to resolve.
#
# Ignoring it here is not papering over a missing extension, because **this
# stage never runs the application**. It downloads dists and writes an
# autoloader; `--no-scripts` means not even a package-discovery command
# executes. The machine whose extensions matter is the runtime stage below,
# and it installs gd and then asserts it is loaded — so the platform check is
# performed where it is meaningful instead of where it is convenient.
#
# This is the same reasoning composer.json's `config.platform.php: "8.3"`
# already rests on: the resolver is told about the target, not about itself.
# The alternative — installing gd into the composer stage — would add
# libpng/libjpeg/freetype and a compile to a stage that exists to fetch zip
# files, to satisfy a check about a machine it is not.
#
# The ignore is scoped to `ext-gd` by name rather than
# `--ignore-platform-reqs`, so a genuinely missing requirement in some future
# dependency still stops the build here.
RUN --mount=type=secret,id=composer_auth \
    if [ -f /run/secrets/composer_auth ]; then \
      export COMPOSER_AUTH="$(cat /run/secrets/composer_auth)"; \
    fi; \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist \
      --ignore-platform-req=ext-gd
COPY . .
RUN --mount=type=secret,id=composer_auth \
    if [ -f /run/secrets/composer_auth ]; then \
      export COMPOSER_AUTH="$(cat /run/secrets/composer_auth)"; \
    fi; \
    composer install --no-dev --no-scripts --optimize-autoloader --prefer-dist \
      --ignore-platform-req=ext-gd

# ---- runtime -------------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

# nginx-mod-http-brotli ships in Alpine's community repo tracking the same
# nginx build this base image's `nginx` package comes from, so it is safe to
# install alongside it without pinning a separate version. This is a `RUN`
# step, not an optional/best-effort one deliberately: if the package name or
# ABI ever drifts on an Alpine bump, the image build fails loudly here
# rather than nginx silently failing to start `load_module` at container
# boot (docker/nginx.conf) on a running host. See docker/nginx.conf for why
# gzip stays enabled alongside brotli rather than replacing it.
#
# `gd` is here for W74's exports (D34): both phpoffice/phpspreadsheet and
# phpoffice/phpword declare `ext-gd` as a hard requirement, not a suggestion,
# so `composer install` refuses to resolve without it and PHP fatals on the
# first workbook if it were installed by an ignore. Neither exporter draws an
# image — the requirement comes from the drawing/chart support elsewhere in
# those libraries — but a dependency's requirement is the dependency's to
# declare, and this image satisfies it rather than arguing with it.
#
# The three image libraries beside it are gd's own: `--with-jpeg` needs
# libjpeg-turbo and `--with-freetype` needs freetype, and both need libpng,
# which gd links unconditionally. They are configured explicitly rather than
# left to gd's defaults so that what this image can decode is a stated fact
# rather than whatever the base image happened to make available — the same
# reasoning the brotli comment above gives for failing loudly on an Alpine
# bump. The `-dev` halves go in `.build-deps` and are removed with it, so the
# runtime layer carries the shared libraries and not their headers.
RUN apk add --no-cache \
        nginx \
        nginx-mod-http-brotli \
        supervisor \
        icu-libs \
        libzip \
        oniguruma \
        libpng \
        libjpeg-turbo \
        freetype \
    && apk add --no-cache --virtual .build-deps \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring intl bcmath zip pcntl opcache gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

# The runtime's half of the bargain struck in the vendor stage, which resolves
# with `--ignore-platform-req=ext-gd` because the `composer:2` image has no gd
# and never runs a line of this application (see there). That ignore is only
# honest if the image that *does* run the code actually has the extension, so
# this asserts it — here, in the stage that would be lying.
#
# A separate `RUN` on purpose: it costs an empty layer and buys a build that
# fails at the step whose name says what went wrong. Folded into the install
# above, a future edit dropping `gd` from the ext list would surface as a
# fatal error in a queue worker on a running host, hours later, in a package
# nobody was editing.
RUN php -r 'exit(extension_loaded("gd") ? 0 : 1);' \
    || (echo "ext-gd missing from the runtime image; phpoffice/* will fatal" && exit 1)

COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build

RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && mkdir -p /var/lib/nginx/tmp /var/log/nginx /run \
    && chown -R www-data:www-data /var/lib/nginx /var/log/nginx /run

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD wget -qO- http://127.0.0.1/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
