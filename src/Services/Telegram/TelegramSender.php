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
        $multipart = [
            ['name' => 'chat_id', 'contents' => (string)$chatId],
        ];
        $mediaGroup = [];

        foreach (array_slice($filePaths, 0, 10) as $idx => $path) {
            $attachName = "file_{$idx}";
            $multipart[] = [
                'name' => $attachName,
                'contents' => fopen($path, 'r'),
                'filename' => "photo_{$idx}.jpg",
            ];
            $mediaGroup[] = [
                'type' => 'photo',
                'media' => "attach://{$attachName}",
                'caption' => ($idx === 0) ? $caption : '',
            ];
        }

        $multipart[] = [
            'name' => 'media',
            'contents' => json_encode($mediaGroup),
        ];

        $this->client->request('POST', $this->baseUrl . 'sendMediaGroup', [
            'multipart' => $multipart,
            'http_errors' => false,
        ]);
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