FROM php:8.2-cli-alpine

# Устанавливаем python3, ffmpeg и yt-dlp
RUN apk add --no-cache git unzip curl python3 py3-pip ffmpeg && \
    curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp && \
    chmod a+rx /usr/local/bin/yt-dlp

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

# Создаем папку для временных видео
RUN mkdir -p /app/downloads && chmod 777 /app/downloads

CMD php bot.php & php -S 0.0.0.0:${PORT:-10000} index.php
