<?php

declare(strict_types=1);

use App\Contracts\MediaDownloaderInterface;
use App\Contracts\MessageSenderInterface;
use App\Contracts\UpdateReceiverInterface; // <-- Импортируем интерфейс
use App\Contracts\UrlResolverInterface;
use App\Dispatcher\UpdateDispatcher;
use App\Handlers\MediaDownloadHandler;
use App\Handlers\StartCommandHandler;
use App\Services\Downloaders\GenericYtDlpDownloader;
use App\Services\Downloaders\TikTokDownloader;
use App\Services\Downloaders\TwitterDownloader;
use App\Services\MediaDownloaderManager;
use App\Services\Telegram\TelegramReceiver;
use App\Services\Telegram\TelegramSender;
use App\Services\Url\CanonicalTikTokUrlResolver;
use DI\ContainerBuilder;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use function DI\autowire;
use function DI\get;

$builder = new ContainerBuilder();

$builder->addDefinitions([
    // 1. Токен бота
    'bot.token' => fn() => getenv('TELEGRAM_BOT_TOKEN') ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? ''),

    // 2. HTTP Клиент
    ClientInterface::class => fn() => new Client(['timeout' => 30.0, 'verify' => false]),
    Client::class => get(ClientInterface::class),

    // 3. Инфраструктура Telegram
    TelegramSender::class => autowire()
        ->constructorParameter('botToken', get('bot.token')),
    
    TelegramReceiver::class => autowire()
        ->constructorParameter('botToken', get('bot.token')),

    // Привязываем интерфейсы к реализациям (LSP / Dependency Inversion)
    MessageSenderInterface::class => get(TelegramSender::class),
    UpdateReceiverInterface::class => get(TelegramReceiver::class), // <--- ВОТ ЭТА СВЯЗКА РЕШАЕТ ОШИБКУ

    // 4. Резолвер ссылок TikTok
    UrlResolverInterface::class => get(CanonicalTikTokUrlResolver::class),

    // 5. Стратегии загрузчиков с таймаутами
    TikTokDownloader::class => autowire()
        ->constructorParameter('socketTimeout', 20),

    TwitterDownloader::class => autowire()
        ->constructorParameter('socketTimeout', 20),

    GenericYtDlpDownloader::class => autowire()
        ->constructorParameter('socketTimeout', 20),

    // 6. Менеджер загрузчиков (Composite)
    MediaDownloaderManager::class => autowire()
        ->constructorParameter('downloaders', [
            get(TikTokDownloader::class),
            get(TwitterDownloader::class),
            get(GenericYtDlpDownloader::class),
        ]),

    MediaDownloaderInterface::class => get(MediaDownloaderManager::class),

    // 7. Диспетчер команд
    UpdateDispatcher::class => autowire()
        ->constructorParameter('handlers', [
            get(StartCommandHandler::class),
            get(MediaDownloadHandler::class),
        ]),
]);

return $builder->build();
