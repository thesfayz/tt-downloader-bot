FROM php:8.2-cli-alpine

# Устанавливаем зависимости, включая ffmpeg и python/pip для стабильного yt-dlp
RUN apk add --no-cache git unzip curl python3 py3-pip ffmpeg && \
    pip install --break-system-packages --no-cache-dir yt-dlp && \
    chmod a+rx /usr/local/bin/yt-dlp

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

# Создаем папку для временных видео (теперь PHP будет использовать именно её)
RUN mkdir -p /app/downloads && chmod 777 /app/downloads

# Запускаем бота и веб-сервер
CMD php bot.php & php -S 0.0.0.0:${PORT:-8080} index.php
