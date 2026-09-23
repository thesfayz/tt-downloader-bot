<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

// Если есть локальный .env — читаем его, если нет — берем системные env
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

$botToken = getenv('TELEGRAM_BOT_TOKEN') ?: ($_ENV['TELEGRAM_BOT_TOKEN'] ?? null);
if (!$botToken) {
    exit("Ошибка: Токен бота не найден в переменных окружения\n");
}

$telegramApi = "https://api.telegram.org/bot{$botToken}/";
$client = new Client(['timeout' => 35.0]);

$offset = 0;
echo "Бот @sfayzttbot запущен. Жду сообщений (Ctrl+C для выхода)...\n";

while (true) {
    try {
        $response = $client->get($telegramApi . 'getUpdates', [
            'query' => [
                'offset'  => $offset,
                'timeout' => 30
            ]
        ]);

        $updates = json_decode($response->getBody()->getContents(), true)['result'] ?? [];

        foreach ($updates as $update) {
            $offset = $update['update_id'] + 1;

            if (!isset($update['message']['text'])) {
                continue;
            }

            $chatId = $update['message']['chat']['id'];
            $text   = trim($update['message']['text']);

            if (preg_match('/https?:\/\/(?:vt|vm|www)\.tiktok\.com\/[^\s]+/i', $text, $matches)) {
                $tiktokUrl = $matches[0];

                $client->post($telegramApi . 'sendMessage', [
                    'json' => [
                        'chat_id' => $chatId,
                        'text'    => 'Качаю видео без водяного знака...'
                    ]
                ]);

                $parserResponse = $client->get('https://www.tikwm.com/api/', [
                    'query' => [
                        'url' => $tiktokUrl,
                        'hd'  => 1
                    ]
                ]);

                $data = json_decode($parserResponse->getBody()->getContents(), true);

                if (isset($data['data']['play'])) {
                    $videoUrl = $data['data']['play'];
                    $title    = $data['data']['title'] ?? 'TikTok Video';

                    $client->post($telegramApi . 'sendVideo', [
                        'json' => [
                            'chat_id' => $chatId,
                            'video'   => $videoUrl,
                            'caption' => mb_substr($title, 0, 100) . "\n\nСкачано через @sfayzttbot"
                        ]
                    ]);
                } else {
                    $client->post($telegramApi . 'sendMessage', [
                        'json' => [
                            'chat_id' => $chatId,
                            'text'    => 'Не удалось найти видео по этой ссылке.'
                        ]
                    ]);
                }
            } else {
                $client->post($telegramApi . 'sendMessage', [
                    'json' => [
                        'chat_id' => $chatId,
                        'text'    => 'Отправь мне ссылку на TikTok (например, https://vt.tiktok.com/...)'
                    ]
                ]);
            }
        }
    } catch (\Throwable $e) {
        echo "Ошибка: " . $e->getMessage() . "\n";
        sleep(2);
    }
}