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

// ВСТАВЬ СЮДА СВОЮ ССЫЛКУ НА WORKER ИЗ ШАГА 2
$workerUrl = "https://tikwm-proxy.sfayzullaev007.workers.dev";

$client = new Client([
    'timeout'         => 30.0,
    'allow_redirects' => true,
]);

$offset = 0;
echo "Бот через Cloudflare Worker запущен...\n";

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
                            'text'    => "Отправь ссылку на TikTok (видео или фото), и я пришлю всё без водяного знака.",
                        ],
                    ]);
                    continue;
                }

                if (preg_match('/https?:\/\/[^\s]+/', $text, $matches)) {
                    $tiktokUrl = $matches[0];

                    $client->post($telegramApiUrl . 'sendMessage', [
                        'json' => [
                            'chat_id' => $chatId,
                            'text'    => "Загружаю без водяного знака...",
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
                                    'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
                                ],
                            ]);

                            $history = $redirectResponse->getHeader('X-Guzzle-Redirect-History');
                            if (!empty($history)) {
                                $tiktokUrl = end($history);
                            }
                        } catch (\Throwable $e) {
                            echo "Ошибка редиректа: " . $e->getMessage() . "\n";
                        }
                    }

                    // Чистый URL
                    preg_match('/\/video\/(\d+)/', $tiktokUrl, $idMatches);
                    $videoId = $idMatches[1] ?? null;
                    $cleanUrl = $videoId ? "https://www.tiktok.com/@i/video/{$videoId}" : strtok($tiktokUrl, '?');

                    echo "Запрос через Cloudflare Worker для: {$cleanUrl}\n";

                    // Стучимся на свой воркер
                    $response = $client->get($workerUrl, [
                        'query' => [
                            'url' => $cleanUrl,
                        ],
                        'http_errors' => false,
                    ]);

                    $body = (string)$response->getBody();
                    $json = json_decode($body, true);

                    if (isset($json['code']) && $json['code'] === 0 && !empty($json['data'])) {
                        $item = $json['data'];

                        // 1. Если это карусель фото
                        if (!empty($item['images']) && is_array($item['images'])) {
                            echo "Карусель из " . count($item['images']) . " фото. Отправляю...\n";
                            $mediaGroup = [];
                            $photos = array_slice($item['images'], 0, 10);
                            foreach ($photos as $i => $imgUrl) {
                                $mediaGroup[] = [
                                    'type'    => 'photo',
                                    'media'   => $imgUrl,
                                    'caption' => ($i === 0) ? 'Скачано через @sfayzttbot' : '',
                                ];
                            }

                            $client->post($telegramApiUrl . 'sendMediaGroup', [
                                'json' => [
                                    'chat_id' => $chatId,
                                    'media'   => $mediaGroup,
                                ],
                            ]);
                            echo "Фотографии отправлены.\n";
                            continue;
                        }

                        // 2. Если это видео
                        $videoUrl = $item['play'] ?? null;
                        if ($videoUrl) {
                            if (!str_starts_with($videoUrl, 'http')) {
                                $videoUrl = 'https://www.tikwm.com' . $videoUrl;
                            }

                            echo "Отправляю видео напрямую...\n";
                            $client->post($telegramApiUrl . 'sendVideo', [
                                'json' => [
                                    'chat_id'            => $chatId,
                                    'video'              => $videoUrl,
                                    'caption'            => 'Скачано через @sfayzttbot',
                                    'supports_streaming' => true,
                                ],
                            ]);
                            echo "Видео отправлено.\n";
                            continue;
                        }
                    }

                    echo "Ошибка ответа от воркера: {$body}\n";
                    $client->post($telegramApiUrl . 'sendMessage', [
                        'json' => [
                            'chat_id' => $chatId,
                            'text'    => "Не удалось получить медиа по этой ссылке.",
                        ],
                    ]);
                }
            }
        }
    } catch (\Throwable $e) {
        echo "Ошибка цикла: " . $e->getMessage() . "\n";
        sleep(2);
    }

    usleep(500000);
}
