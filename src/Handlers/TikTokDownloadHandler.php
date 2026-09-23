<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Contracts\HandlerInterface;
use App\Contracts\MediaDownloaderInterface;
use App\Contracts\MessageSenderInterface;
use App\Contracts\UrlResolverInterface;
use App\DTO\MediaType;

final class TikTokDownloadHandler implements HandlerInterface
{
    public function __construct(
        private readonly MessageSenderInterface $sender,
        private readonly UrlResolverInterface $urlResolver,
        private readonly MediaDownloaderInterface $downloader
    ) {}

    public function supports(string $text): bool
    {
        return (bool) preg_match('/https?:\/\/[^\s]+/', $text);
    }

    public function handle(int $chatId, string $text): void
    {
        preg_match('/https?:\/\/[^\s]+/', $text, $matches);
        $rawUrl = $matches[0];

        $this->sender->sendMessage($chatId, "Загружаю, подожди пару секунд...");

        $resolvedUrl = $this->urlResolver->resolve($rawUrl);
        echo "Обработка URL: {$resolvedUrl}\n";

        $result = $this->downloader->download($resolvedUrl);

        if ($result === null) {
            $this->sender->sendMessage($chatId, "Не удалось загрузить медиа по этой ссылке.");
            return;
        }

        try {
            if ($result->type === MediaType::CAROUSEL) {
                $count = count($result->filePaths);
                if ($count === 1) {
                    $this->sender->sendPhoto($chatId, $result->filePaths[0], 'Скачано через @sfayzttbot');
                } else {
                    $this->sender->sendMediaGroup($chatId, $result->filePaths, 'Скачано через @sfayzttbot');
                }
                echo "Карусель ({$count} фото) доставлена.\n";
            } elseif ($result->type === MediaType::VIDEO) {
                $this->sender->sendVideo($chatId, $result->filePaths[0], 'Скачано через @sfayzttbot');
                echo "Видео доставлено.\n";
            }
        } catch (\Throwable $e) {
            echo "Ошибка отправки: " . $e->getMessage() . "\n";
            $this->sender->sendMessage($chatId, "Произошла ошибка при отправке файла в чат.");
        } finally {
            $result->cleanup();
        }
    }
}