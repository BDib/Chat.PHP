FROM php:8.3-fpm-alpine

# Alpine uses apk, not apt
RUN apk add --no-cache \
    postgresql-dev \
    sqlite-dev \
    && docker-php-ext-install pdo pdo_mysql pdo_pgsql

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/chat

COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN mkdir -p db && chown -R www-data:www-data /var/www/chat/db /var/www/chat/words.txt

EXPOSE 9000
CMD ["php-fpm"]
