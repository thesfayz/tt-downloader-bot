FROM php:8.2-cli-alpine

# Устанавливаем системные зависимости для composer и curl
RUN apk add --no-cache git unzip curl

# Устанавливаем Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Копируем зависимости и ставим их
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

# Копируем весь остальной код
COPY . .

# Запускаем бота
CMD ["php", "bot.php"]
