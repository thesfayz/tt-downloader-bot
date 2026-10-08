FROM php:8.2-cli-alpine

# Устанавливаем зависимости
RUN apk add --no-cache git unzip curl python3 py3-pip ffmpeg

# Принудительно создаем симлинк python -> python3 (перезаписываем если есть)
RUN ln -sf /usr/bin/python3 /usr/bin/python

# Устанавливаем yt-dlp
RUN pip install --no-cache-dir yt-dlp

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

RUN mkdir -p /app/downloads && chmod 777 /app/downloads

CMD php bot.php & php -S 0.0.0.0:${PORT:-8080} index.php
