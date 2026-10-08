FROM php:8.2-cli-alpine

# Устанавливаем зависимости
RUN apk add --no-cache git unzip curl ffmpeg

# Скачиваем yt-dlp напрямую
RUN curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp && \
    chmod +x /usr/local/bin/yt-dlp

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

RUN mkdir -p /app/downloads && chmod 777 /app/downloads

CMD php bot.php & php -S 0.0.0.0:${PORT:-8080} index.php
