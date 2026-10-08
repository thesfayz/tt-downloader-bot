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
        try {
            $response = $this->client->request('GET', $this->uri, [
                'query' => [
                    'offset' => $offset,
                    'timeout' => $timeout,
                ],
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            echo '[TelegramReceiver] Сетевая ошибка getUpdates: ' . $e->getMessage() . "\n";
            return [];
        }

        $body = (string)$response->getBody();
        $data = json_decode($body, true);

        if ($response->getStatusCode() !== 200 || !is_array($data)) {
            echo "[TelegramReceiver] getUpdates HTTP {$response->getStatusCode()}: {$body}\n";

            // 409 Conflict — где-то запущен второй экземпляр бота или включён Webhook.
            if (($data['error_code'] ?? null) === 409) {
                echo "[TelegramReceiver] Конфликт: скорее всего бот запущен в двух местах "
                    . "или у него включён webhook. Пробую сбросить webhook...\n";
                $this->deleteWebhook();
            }

            return [];
        }

        if (($data['ok'] ?? false) !== true) {
            echo '[TelegramReceiver] getUpdates вернул ok=false: ' . $body . "\n";
            return [];
        }

        return $data['result'] ?? [];
    }

    private function deleteWebhook(): void
    {
        try {
            $this->client->request(
                'POST',
                str_replace('getUpdates', 'deleteWebhook', $this->uri),
                ['http_errors' => false, 'timeout' => 10]
            );
        } catch (\Throwable) {
            // игнорируем — следующая итерация polling сама всё покажет в логах
        }
    }
}
