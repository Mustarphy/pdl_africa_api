# syntax=docker/dockerfile:1
#
# PDL Africa API: Laravel 13 on PHP 8.4 + Apache (mod_php), for Render and
# local Docker. Build:  docker build -t pdl-africa-api .
# See DEPLOYMENT.md for the environment variables the container needs.

ARG PHP_IMAGE=php:8.4.26-apache-bookworm

# --- Base: PHP + the extensions this app uses ------------------------------
FROM ${PHP_IMAGE} AS base

# install-php-extensions pulls in the right system libraries per extension.
COPY --from=mlocati/php-extension-installer:2.12.3 /usr/bin/install-php-extensions /usr/local/bin/
# pdo_mysql: MySQL/MariaDB · gd (JPEG/PNG/WebP): catalogue image processing ·
# exif: photo orientation · intl: Laravel number/locale helpers · opcache.
RUN install-php-extensions pdo_mysql gd exif intl opcache

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/production.ini "$PHP_INI_DIR/conf.d/zz-production.ini"

# --- Dependencies: Composer runs only in this stage ------------------------
FROM base AS vendor

COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer
# zip/unzip let Composer extract dist archives.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
        --no-scripts --no-autoloader

COPY . .
# Runs Laravel's package discovery (post-autoload-dump) for production
# packages only.
RUN composer dump-autoload --no-dev --optimize --no-interaction

# --- Runtime image ----------------------------------------------------------
FROM base AS runtime

# Apache: serve public/, listen on $PORT, allow public/.htaccess rewrites.
RUN a2enmod rewrite headers \
    && sed -ri 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -ri '/Listen 443/d' /etc/apache2/ports.conf
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache/security.conf /etc/apache2/conf-available/zz-pdl-security.conf
RUN a2enconf zz-pdl-security

WORKDIR /var/www/html
# Application code is owned by root and read-only for the web server user.
COPY --from=vendor /var/www/html /var/www/html
COPY --chmod=0755 docker/entrypoint.sh /usr/local/bin/pdl-entrypoint

# Only the runtime directories are writable by www-data. public/storage
# serves uploaded catalogue images.
RUN chown root:root /var/www/html && chmod 0755 /var/www/html \
    && mkdir -p storage/app/public/catalog storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs \
        bootstrap/cache \
    && ln -sfn ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chown www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2

# Render sets PORT (default 10000); ports above 1024 need no root.
ENV PORT=10000 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

USER www-data
EXPOSE 10000
ENTRYPOINT ["pdl-entrypoint"]
