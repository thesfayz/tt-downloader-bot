<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\UpdateReceiverInterface;
use GuzzleHttp\ClientInterface;

final class TelegramReceiver implements UpdateReceiverInterface
{
    private string $uri;

    public function __construct(
        private readonly ClientInterface $client,
        string $botToken
    ) {
        $this->uri = "https://api.telegram.org/bot{$botToken}/getUpdates";
    }

    public function getUpdates(int $offset, int $timeout = 15): array
    {
        $response = $this->client->request('GET', $this->uri, [
            'query' => [
                'offset' => $offset,
                'timeout' => $timeout,
            ],
            'http_errors' => false,
        ]);

        if ($response->getStatusCode() === 200) {
            $data = json_decode((string)$response->getBody(), true);
            return $data['result'] ?? [];
        }

        return [];
    }
}