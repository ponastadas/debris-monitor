# Stage 1: Composer deps
FROM composer:2.7 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

# Stage 2: Production
FROM php:8.3-fpm-alpine AS production
RUN apk add --no-cache nginx supervisor curl \
    && docker-php-ext-install pdo pdo_mysql opcache
WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache
HEALTHCHECK --interval=30s --timeout=5s CMD curl -f http://localhost/api/health || exit 1
EXPOSE 80

# Stage 3: Development (hot reload)
FROM php:8.3-cli-alpine AS development
RUN docker-php-ext-install pdo pdo_mysql
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-interaction --prefer-dist
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
