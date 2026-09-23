FROM php:8.2-cli-alpine

RUN apk add --no-cache git unzip curl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

# Запускаем бота в фоне, а на основном процессе держим веб-порт для Render
CMD php bot.php & php -S 0.0.0.0:${PORT:-10000} index.php
