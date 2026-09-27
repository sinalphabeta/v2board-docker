# V2board 运行镜像：php-fpm 8.2 + pdo_mysql / redis / pcntl / zip + composer
# 官方镜像默认加载 opcache 且在 fpm 中启用，保持默认即可；不要在 fpm 里把它关掉——
# "加载但未启用"时 opcache_reset() 返回 false，ConfigController@save 会直接 500。
ARG PHP_BASE_IMAGE=php:8.2-fpm-bookworm

FROM mlocati/php-extension-installer:latest AS ext-installer
FROM composer:2 AS composer

FROM ${PHP_BASE_IMAGE}

ARG APT_MIRROR=
RUN set -eux; \
    if [ -n "$APT_MIRROR" ]; then \
      sed -i "s|deb.debian.org|${APT_MIRROR}|g" /etc/apt/sources.list.d/debian.sources; \
    fi; \
    apt-get update; \
    apt-get install -y --no-install-recommends git unzip; \
    rm -rf /var/lib/apt/lists/*

COPY --from=ext-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql redis pcntl zip

COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_HOME=/composer \
    COMPOSER_CACHE_DIR=/composer/cache \
    COMPOSER_ALLOW_SUPERUSER=1

COPY zz-v2board.ini /usr/local/etc/php/conf.d/zz-v2board.ini
COPY zz-fpm.conf /usr/local/etc/php-fpm.d/zz-fpm.conf
COPY init.sh /usr/local/bin/v2b-init
RUN chmod +x /usr/local/bin/v2b-init

WORKDIR /var/www/html
