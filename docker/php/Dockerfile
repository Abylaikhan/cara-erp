# syntax=docker/dockerfile:1
# PHP-FPM + Composer image for PromTech ERP (Laravel 13).
# Base images are pinned to exact versions; update them deliberately and record the change in docs/adr.
FROM composer:2.10.3 AS composer

FROM php:8.4.26-fpm-bookworm

ARG UID=1000
ARG GID=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libicu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip bcmath pcntl \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-promtech.ini

# Run as an unprivileged user whose UID/GID match the host user, so bind-mounted files
# keep normal ownership (no chmod 777). GID may already exist in the image (e.g. 20 on macOS).
RUN if ! getent group "${GID}" >/dev/null; then groupadd -g "${GID}" app; fi \
    && useradd -o -m -u "${UID}" -g "${GID}" -s /bin/bash app \
    && mkdir -p /home/app/.composer/cache /var/www/html \
    && chown -R "${UID}:${GID}" /home/app /var/www/html \
    && sed -i -e '/^user = /d' -e '/^group = /d' /usr/local/etc/php-fpm.d/www.conf

ENV COMPOSER_HOME=/home/app/.composer
WORKDIR /var/www/html
USER app

EXPOSE 9000
CMD ["php-fpm"]
