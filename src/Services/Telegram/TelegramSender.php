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
        $this->client->request('POST', $this->baseUrl . 'sendMessage', [
            'json' => [
                'chat_id' => $chatId,
                'text' => $text,
            ],
            'http_errors' => false,
        ]);
    }

    public function sendPhoto(int $chatId, string $filePath, string $caption = ''): void
    {
        $this->client->request('POST', $this->baseUrl . 'sendPhoto', [
            'multipart' => [
                ['name' => 'chat_id', 'contents' => (string)$chatId],
                ['name' => 'photo',   'contents' => fopen($filePath, 'r'), 'filename' => 'photo.jpg'],
                ['name' => 'caption', 'contents' => $caption],
            ],
            'http_errors' => false,
        ]);
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
                $attachKey = "photo_{$chunkIndex}_{$idx}";

                $multipart[] = [
                    'name'     => $attachKey,
                    'contents' => fopen($filePath, 'r'),
                    'filename' => "photo_{$idx}.jpg",
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
                echo "Ошибка Telegram API при отправке альбома: " . (string)$response->getBody() . "\n";
            }
        }
    }

    public function sendVideo(int $chatId, string $filePath, string $caption = ''): void
    {
        $this->client->request('POST', $this->baseUrl . 'sendVideo', [
            'multipart' => [
                ['name' => 'chat_id',            'contents' => (string)$chatId],
                ['name' => 'video',              'contents' => fopen($filePath, 'r'), 'filename' => 'video.mp4'],
                ['name' => 'caption',            'contents' => $caption],
                ['name' => 'supports_streaming', 'contents' => 'true'],
            ],
            'http_errors' => false,
        ]);
    }
}