FROM php:8.2-cli-alpine

# Устанавливаем зависимости (python3 нужен yt-dlp для внутренних операций)
RUN apk add --no-cache git unzip curl wget python3 ffmpeg linux-headers \
    && docker-php-ext-install curl

# Скачиваем готовый бинарник yt-dlp (не требует pip)
RUN wget -qO /usr/local/bin/yt-dlp https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp && \
    chmod +x /usr/local/bin/yt-dlp

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

COPY . .

RUN mkdir -p /app/downloads && chmod 777 /app/downloads

# Supervisord держит оба процесса (бот + HTTP для UptimeRobot) и ПЕРЕЗАПУСКАЕТ
# бота, если он упадёт. Без этого любой фатал в боте = живой веб и мёртвый бот.
RUN apk add --no-cache supervisor

COPY supervisord.conf /etc/supervisord.conf

# PID 1 = supervisord; логи обоих процессов идут в stdout контейнера (видно в Render Logs)
CMD ["supervisord", "-c", "/etc/supervisord.conf", "-n"]
