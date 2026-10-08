FROM php:8.2-cli-alpine

# Устанавливаем зависимости (python3 нужен yt-dlp для внутренних операций)
RUN apk add --no-cache git unzip curl wget python3 ffmpeg

# Скачиваем готовый бинарник yt-dlp (не требует pip)
RUN wget -qO /usr/local/bin/yt-dlp https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp && \
    chmod +x /usr/local/bin/yt-dlp

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

RUN mkdir -p /app/downloads && chmod 777 /app/downloads

CMD php bot.php & php -S 0.0.0.0:${PORT:-8080} index.php
