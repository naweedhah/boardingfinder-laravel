# syntax=docker/dockerfile:1

# ---- 1. PHP dependencies (no dev packages) ---------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ---- 2. Runtime ------------------------------------------------------------
FROM php:8.4-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libzip-dev libsqlite3-dev unzip \
 && docker-php-ext-install pdo_pgsql pdo_mysql pdo_sqlite pcntl zip bcmath \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www
COPY --from=vendor /app /var/www
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
 && mkdir -p storage/framework/cache storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 8800 8080
ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8800"]
