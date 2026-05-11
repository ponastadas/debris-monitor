# Stage 1: Composer deps (isolated — no source code in cache layer)
FROM composer:2.7 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

# Stage 2: Production (PHP-FPM + Nginx, managed by Supervisor)
FROM php:8.3-fpm-alpine AS production

RUN apk add --no-cache nginx supervisor curl mysql-client \
    && docker-php-ext-install pdo pdo_mysql opcache

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

# Runtime configs — placed after COPY . . so they override any accidentally committed versions
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s \
    CMD curl -sf http://localhost/api/health || exit 1

EXPOSE 80

# Supervisor starts both php-fpm (port 9000) and nginx (port 80)
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]

# Stage 3: Development (php artisan serve, hot reload via volume mount)
FROM php:8.3-cli-alpine AS development

RUN apk add --no-cache curl mysql-client \
    && docker-php-ext-install pdo pdo_mysql \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html
COPY . .
RUN composer install --no-interaction --prefer-dist

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
