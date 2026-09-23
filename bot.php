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
    'timeout'         => 30.0,
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

                    // Разворачиваем короткие ссылки (vt.tiktok.com, vm.tiktok.com)
                    if (str_contains($tiktokUrl, 'vt.tiktok.com') || str_contains($tiktokUrl, 'vm.tiktok.com')) {
                        try {
                            $redirectResponse = $client->get($tiktokUrl, [
                                'allow_redirects' => [
                                    'max'             => 10,
                                    'track_redirects' => true,
                                ],
                                'headers' => [
                                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                                ],
                            ]);

                            $history = $redirectResponse->getHeader('X-Guzzle-Redirect-History');
                            if (!empty($history)) {
                                $tiktokUrl = end($history);
                                echo "Развернутая ссылка: {$tiktokUrl}\n";
                            }
                        } catch (\Throwable $e) {
                            echo "Ошибка разворота редиректа: " . $e->getMessage() . "\n";
                        }
                    }

                    // Запрос к TikWM API с браузерными заголовками
                    $parserResponse = $client->get('https://www.tikwm.com/api/', [
                        'query' => [
                            'url' => $tiktokUrl,
                            'hd'  => 1,
                        ],
                        'headers' => [
                            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                            'Accept'          => 'application/json, text/javascript, */*; q=0.01',
                            'Accept-Language' => 'en-US,en;q=0.9',
                            'Referer'         => 'https://www.tikwm.com/',
                            'Origin'          => 'https://www.tikwm.com',
                            'X-Requested-With'=> 'XMLHttpRequest',
                        ],
                        'http_errors' => false,
                    ]);

                    $rawBody = (string)$parserResponse->getBody();
                    echo "HTTP статус TikWM: " . $parserResponse->getStatusCode() . "\n";
                    echo "TikWM response: " . $rawBody . "\n";

                    $data = json_decode($rawBody, true);

                    if (isset($data['code']) && $data['code'] === 0 && !empty($data['data']['play'])) {
                        $videoUrl = $data['data']['play'];

                        // Отправляем видео пользователю
                        $client->post($telegramApiUrl . 'sendVideo', [
                            'json' => [
                                'chat_id'             => $chatId,
                                'video'               => $videoUrl,
                                'caption'             => 'Скачано через @sfayzttbot',
                                'supports_streaming'  => true,
                            ],
                        ]);
                    } else {
                        $client->post($telegramApiUrl . 'sendMessage', [
                            'json' => [
                                'chat_id' => $chatId,
                                'text'    => "Не удалось найти видео по этой ссылке.",
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

    usleep(500000); // 0.5 сек задержки между запросами
}
