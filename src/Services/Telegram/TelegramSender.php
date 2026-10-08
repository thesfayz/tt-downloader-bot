<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\MessageSenderInterface;
use GuzzleHttp\ClientInterface;

final class TelegramSender implements MessageSenderInterface
{
    private string $baseUrl;

    public function __construct(
        private readonly ClientInterface $client,
        string $botToken
    ) {
        $this->baseUrl = "https://api.telegram.org/bot{$botToken}/";
    }

    public function sendMessage(int $chatId, string $text): void
    {
        $response = $this->client->request('POST', $this->baseUrl . 'sendMessage', [
            'json' => [
                'chat_id' => $chatId,
                'text' => $text,
            ],
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() !== 200) {
            echo "[TelegramSender] sendMessage HTTP {$response->getStatusCode()}: "
                . (string)$response->getBody() . "\n";
        }
    }

    public function sendPhoto(int $chatId, string $filePath, string $caption = ''): void
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("Файл не найден: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть файл: {$filePath}");
        }

        $response = $this->client->request('POST', $this->baseUrl . 'sendPhoto', [
            'multipart' => [
                ['name' => 'chat_id', 'contents' => (string)$chatId],
                ['name' => 'photo',   'contents' => $handle, 'filename' => basename($filePath)],
                ['name' => 'caption', 'contents' => $caption],
            ],
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() !== 200) {
            echo "[TelegramSender] sendPhoto HTTP {$response->getStatusCode()}: "
                . (string)$response->getBody() . "\n";
        }
    }

    public function sendMediaGroup(int $chatId, array $filePaths, string $caption = ''): void
    {
        // Telegram поддерживает альбомы размером от 2 до 10 файлов.
        // Если фото больше 10, разбиваем на чанки по 10 штук.
        $chunks = array_chunk($filePaths, 10);

        foreach ($chunks as $chunkIndex => $chunk) {
            $multipart = [
                [
                    'name'     => 'chat_id',
                    'contents' => (string)$chatId,
                ],
            ];

            $mediaGroup = [];

            foreach ($chunk as $idx => $filePath) {
                if (!is_file($filePath)) {
                    continue;
                }
                $handle = fopen($filePath, 'r');
                if ($handle === false) {
                    continue;
                }

                $attachKey = "photo_{$chunkIndex}_{$idx}";

                $multipart[] = [
                    'name'     => $attachKey,
                    'contents' => $handle,
                    'filename' => basename($filePath),
                ];

                $mediaItem = [
                    'type'  => 'photo',
                    'media' => "attach://{$attachKey}",
                ];

                // Подпись добавляем только к первой фотографии первого альбома
                if ($chunkIndex === 0 && $idx === 0 && $caption !== '') {
                    $mediaItem['caption'] = $caption;
                }

                $mediaGroup[] = $mediaItem;
            }

            if ($mediaGroup === []) {
                continue;
            }

            // Одиночное фото отправляем через sendPhoto (sendMediaGroup требует 2+ элементов)
            if (count($mediaGroup) === 1) {
                $this->sendPhoto($chatId, $chunk[array_key_first($chunk)] ?? '', $caption);
                continue;
            }

            $multipart[] = [
                'name'     => 'media',
                'contents' => json_encode($mediaGroup, JSON_UNESCAPED_SLASHES),
            ];

            $response = $this->client->request('POST', $this->baseUrl . 'sendMediaGroup', [
                'multipart'   => $multipart,
                'http_errors' => false,
            ]);

            // Если Telegram отклонил медиагруппу — пишем в лог для отладки
            if ($response->getStatusCode() !== 200) {
                echo "[TelegramSender] sendMediaGroup HTTP {$response->getStatusCode()}: "
                    . (string)$response->getBody() . "\n";
            }
        }
    }

    public function sendVideo(int $chatId, string $filePath, string $caption = ''): void
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException("Файл не найден: {$filePath}");
        }

        $size = filesize($filePath);
        if ($size === false || $size > 50 * 1024 * 1024) {
            $this->sendMessage(
                $chatId,
                'Видео слишком большое для прямой отправки (лимит Telegram — 50 МБ).'
            );
            return;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Не удалось открыть файл: {$filePath}");
        }

        $multipart = [
            ['name' => 'chat_id', 'contents' => (string)$chatId],
            ['name' => 'video',   'contents' => $handle, 'filename' => basename($filePath)],
            ['name' => 'supports_streaming', 'contents' => 'true'],
        ];

        if ($caption !== '') {
            $multipart[] = ['name' => 'caption', 'contents' => $caption];
        }

        $response = $this->client->request('POST', $this->baseUrl . 'sendVideo', [
            'multipart'   => $multipart,
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() !== 200) {
            echo "[TelegramSender] sendVideo HTTP {$response->getStatusCode()}: "
                . (string)$response->getBody() . "\n";
        }
    }
}
