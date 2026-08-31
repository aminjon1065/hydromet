# syntax=docker/dockerfile:1

# Runtime image for the application, queue worker and scheduler services.
# Application code is bind-mounted by compose.yaml; a release image for the
# VPS adds a COPY step and a compiled asset bundle.
FROM php:8.5-fpm-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache \
        fcgi \
        icu-libs \
        libpq \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libpq-dev \
        libzip-dev \
        linux-headers \
    # OPcache is deliberately absent: the official php:8.5 images compile it
    # statically (php -m lists "Zend OPcache" and no opcache.so exists), so
    # docker-php-ext-install opcache builds no shared module and fails with
    # "cp: can't stat 'modules/*'". It is configured in php.ini instead.
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        pcntl \
        pdo_pgsql \
        pgsql \
        zip \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-portal.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-portal.conf

# Run as a non-root user whose ids match the host developer account so
# bind-mounted files keep usable ownership.
#
# The home directory deliberately sits outside WORKDIR: with home set to
# /var/www/html, tools that write dotfiles (psysh under `artisan tinker`,
# Composer, npm) dropped runtime state such as .config/psysh/psysh_history
# straight into the bind-mounted working copy.
RUN addgroup -g ${GID} -S portal \
    && adduser -u ${UID} -G portal -S -h /home/portal portal \
    && mkdir -p /home/portal /var/www/html \
    && chown -R portal:portal /home/portal /var/www/html

# runc derives HOME from /etc/passwd, but declare it explicitly so the value
# does not depend on the runtime, and keep tool caches under it as well.
ENV HOME=/home/portal \
    COMPOSER_HOME=/home/portal/.composer \
    XDG_CONFIG_HOME=/home/portal/.config \
    XDG_CACHE_HOME=/home/portal/.cache \
    XDG_DATA_HOME=/home/portal/.local/share

WORKDIR /var/www/html
USER portal

EXPOSE 9000
CMD ["php-fpm"]
