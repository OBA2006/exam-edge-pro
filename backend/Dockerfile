FROM php:8.3-fpm-alpine
RUN apk add --no-cache git curl libpng-dev libxml2-dev zip unzip postgresql-dev oniguruma-dev \
    && docker-php-ext-install pdo pdo_pgsql mbstring exif pcntl bcmath gd
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json ./
COPY composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts
COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/app/public bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache
EXPOSE 8000
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8000"]
