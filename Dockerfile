# syntax=docker/dockerfile:1

# ---- фронт: собираем SPA ----
FROM node:20-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.ts tsconfig.json ./
COPY resources ./resources
RUN npm run build

# ---- PHP с нужными расширениями ----
FROM php:8.3-fpm-alpine AS base
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pcntl intl opcache \
    && apk add --no-cache su-exec
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# ---- для разработки и тестов: код монтируется снаружи ----
FROM base AS dev
RUN apk add --no-cache git unzip

# ---- образ приложения (php-fpm, воркер, планировщик) ----
FROM base AS app
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
COPY --from=assets /app/public/build public/build
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---- nginx со статикой ----
FROM nginx:1.27-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build
