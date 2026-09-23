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

                    // 1. Разворачиваем короткие ссылки (vt.tiktok.com, vm.tiktok.com)
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

                    // 2. Достаем ID видео из ссылки
                    preg_match('/\/video\/(\d+)/', $tiktokUrl, $idMatches);
                    $videoId = $idMatches[1] ?? null;

                    if (!$videoId) {
                        $client->post($telegramApiUrl . 'sendMessage', [
                            'json' => [
                                'chat_id' => $chatId,
                                'text'    => "Не удалось извлечь ID видео из ссылки.",
                            ],
                        ]);
                        continue;
                    }

                    echo "Извлечен Video ID: {$videoId}\n";

                    // 3. Запрос напрямую к мобильному API TikTok (без Cloudflare прокси)
                    $tiktokApiResponse = $client->get('https://api16-normal-c-useast1a.tiktokv.com/aweme/v1/feed/', [
                        'query' => [
                            'aweme_id' => $videoId,
                        ],
                        'headers' => [
                            'User-Agent' => 'com.zhiliaoapp.musically/2022600030 (Linux; U; Android 10; en_US; Pixel 4; Build/QQ3A.200805.001; Cronet/TTNetVersion:b4d74d15)',
                            'Accept'     => 'application/json',
                        ],
                        'http_errors' => false,
                    ]);

                    $rawBody = (string)$tiktokApiResponse->getBody();
                    echo "HTTP статус TikTok API: " . $tiktokApiResponse->getStatusCode() . "\n";

                    $data = json_decode($rawBody, true);
                    $videoUrl = $data['aweme_list'][0]['video']['play_addr']['url_list'][0] ?? null;

                    if ($videoUrl) {
                        echo "Получена прямая ссылка на видео, отправляю в TG...\n";
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
                                'text'    => "Не удалось получить видео (возможно, приватное или удалено).",
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

    usleep(500000); // 0.5 сек паузы между запросами
}
