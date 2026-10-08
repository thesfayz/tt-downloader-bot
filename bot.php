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
    try {
        Dotenv::createImmutable(__DIR__)->load();
    } catch (\Throwable $e) {
        exit("Ошибка загрузки .env: " . $e->getMessage() . "\n");
    }
}

$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? null);
if (!$botToken) {
    exit("Ошибка: Токен бота не найден\n");
}

// Проверка токена через getMe — сразу видно, валидный ли он и доступен ли API.
$checkClient = new Client(['timeout' => 15.0]);
try {
    $meResponse = $checkClient->request('GET', "https://api.telegram.org/bot{$botToken}/getMe", [
        'http_errors' => false,
    ]);
    $meData = json_decode((string)$meResponse->getBody(), true);

    if ($meResponse->getStatusCode() !== 200 || ($meData['ok'] ?? false) !== true) {
        echo "Ошибка: Telegram отверг токен (HTTP {$meResponse->getStatusCode()}): "
            . (string)$meResponse->getBody() . "\n";
        exit(1);
    }

    echo "Токен OK. Бот: @" . ($meData['result']['username'] ?? '?') . "\n";

    // Страховка: если где-то был выставлен webhook, polling не работал бы никогда.
    $checkClient->request(
        'POST',
        "https://api.telegram.org/bot{$botToken}/deleteWebhook",
        ['http_errors' => false, 'timeout' => 10]
    );
} catch (\Throwable $e) {
    echo "Не удалось связаться с Telegram API: " . $e->getMessage() . "\n";
    exit(1);
}

$httpClient = new Client(['timeout' => 30.0, 'connect_timeout' => 10.0, 'verify' => false]);

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
