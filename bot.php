<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? null);
if (!$botToken) {
    exit("Ошибка: Токен бота не найден в переменных окружения\n");
}

$telegramApiUrl = "https://api.telegram.org/bot{$botToken}/";

$client = new Client([
    'timeout'         => 45.0,
    'allow_redirects' => true,
]);

$offset = 0;
echo "Бот запущен. Ожидание сообщений...\n";

while (true) {
    try {
        $updatesResponse = $client->get($telegramApiUrl . 'getUpdates', [
            'query' => [
                'offset'  => $offset,
                'timeout' => 20,
            ],
        ]);

        $updates = json_decode($updatesResponse->getBody(), true);

        if (!empty($updates['result'])) {
            foreach ($updates['result'] as $update) {
                $offset = $update['update_id'] + 1;

                if (!isset($update['message']['text'])) {
                    continue;
                }

                $chatId = $update['message']['chat']['id'];
                $text   = trim($update['message']['text']);

                if ($text === '/start') {
                    $client->post($telegramApiUrl . 'sendMessage', [
                        'json' => [
                            'chat_id' => $chatId,
                            'text'    => "Привет! Отправь мне ссылку на видео из TikTok, и я пришлю его без водяного знака.",
                        ],
                    ]);
                    continue;
                }

                if (preg_match('/https?:\/\/[^\s]+/', $text, $matches)) {
                    $tiktokUrl = $matches[0];

                    $client->post($telegramApiUrl . 'sendMessage', [
                        'json' => [
                            'chat_id' => $chatId,
                            'text'    => "Качаю видео без водяного знака...",
                        ],
                    ]);

                    // Разворачиваем короткие ссылки
                    if (str_contains($tiktokUrl, 'vt.tiktok.com') || str_contains($tiktokUrl, 'vm.tiktok.com')) {
                        try {
                            $redirectResponse = $client->get($tiktokUrl, [
                                'allow_redirects' => [
                                    'max'             => 10,
                                    'track_redirects' => true,
                                ],
                                'headers' => [
                                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36',
                                ],
                            ]);

                            $history = $redirectResponse->getHeader('X-Guzzle-Redirect-History');
                            if (!empty($history)) {
                                $tiktokUrl = end($history);
                                echo "Развернутая ссылка: {$tiktokUrl}\n";
                            }
                        } catch (\Throwable $e) {
                            echo "Ошибка редиректа: " . $e->getMessage() . "\n";
                        }
                    }

                    // Используем Cobalt API (не режет дата-центры)
                    $cobaltResponse = $client->post('https://api.cobalt.tools/api/json', [
                        'json' => [
                            'url'          => $tiktokUrl,
                            'vQuality'     => 'max',
                            'filenamePattern' => 'classic'
                        ],
                        'headers' => [
                            'Accept'       => 'application/json',
                            'Content-Type' => 'application/json',
                            'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36'
                        ],
                        'http_errors' => false,
                    ]);

                    $rawBody = (string)$cobaltResponse->getBody();
                    echo "HTTP статус Cobalt: " . $cobaltResponse->getStatusCode() . "\n";
                    echo "Ответ API: " . $rawBody . "\n";

                    $data = json_decode($rawBody, true);
                    $videoUrl = $data['url'] ?? null;

                    if ($videoUrl) {
                        echo "Отправка видео в Telegram...\n";
                        $client->post($telegramApiUrl . 'sendVideo', [
                            'json' => [
                                'chat_id'            => $chatId,
                                'video'              => $videoUrl,
                                'caption'            => 'Скачано через @sfayzttbot',
                                'supports_streaming' => true,
                            ],
                        ]);
                    } else {
                        $client->post($telegramApiUrl . 'sendMessage', [
                            'json' => [
                                'chat_id' => $chatId,
                                'text'    => "Не удалось получить прямую ссылку на видео.",
                            ],
                        ]);
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        echo "Ошибка: " . $e->getMessage() . "\n";
        sleep(2);
    }

    usleep(500000);
}
