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
RUN addgroup -g ${GID} -S portal \
    && adduser -u ${UID} -G portal -S -h /var/www/html portal \
    && chown -R portal:portal /var/www/html

WORKDIR /var/www/html
USER portal

EXPOSE 9000
CMD ["php-fpm"]
