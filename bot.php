<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\BotRunner;
use App\Dispatcher\UpdateDispatcher;
use App\Handlers\StartCommandHandler;
use App\Handlers\TikTokDownloadHandler;
use App\Services\Telegram\TelegramReceiver;
use App\Services\Telegram\TelegramSender;
use App\Services\Url\CanonicalTikTokUrlResolver;
use App\Services\Url\RedirectResolver;
use App\Services\Url\TikTokIdExtractor;
use App\Services\Url\TikTokUrlSanitizer;
use App\Services\YtDlpDownloader;
use Dotenv\Dotenv;
use GuzzleHttp\Client;

if (file_exists(__DIR__ . '/.env')) {
    Dotenv::createImmutable(__DIR__)->load();
}

$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? null);
if (!$botToken) {
    exit("Ошибка: Токен бота не найден\n");
}

$httpClient = new Client(['timeout' => 30.0, 'verify' => false]);

// Инфраструктура Telegram
$receiver = new TelegramReceiver($httpClient, $botToken);
$sender   = new TelegramSender($httpClient, $botToken);

// Сервисы TikTok
$urlResolver = new CanonicalTikTokUrlResolver(
    redirectResolver: new RedirectResolver($httpClient),
    idExtractor:      new TikTokIdExtractor(),
    sanitizer:        new TikTokUrlSanitizer()
);
$downloader = new YtDlpDownloader(socketTimeout: 20);

// Диспетчер команд
$dispatcher = new UpdateDispatcher([
    new StartCommandHandler($sender),
    new TikTokDownloadHandler($sender, $urlResolver, $downloader),
]);

// Запуск
(new BotRunner($receiver, $dispatcher))->run();