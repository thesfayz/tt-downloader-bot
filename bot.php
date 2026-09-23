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
$workerUrl = "https://tikwm-proxy.sfayzullaev007.workers.dev/";

$client = new Client([
    'timeout'         => 60.0,
    'allow_redirects' => true,
]);

$offset = 0;
echo "Бот через Cloudflare Worker (с прямой передачей потока) запущен...\n";

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

                    // Разворачиваем короткие ссылки vt/vm
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

                    preg_match('/\/video\/(\d+)/', $tiktokUrl, $idMatches);
                    $videoId = $idMatches[1] ?? null;
                    $cleanUrl = $videoId ? "https://www.tiktok.com/@i/video/{$videoId}" : strtok($tiktokUrl, '?');

                    echo "Запрос через воркер для: {$cleanUrl}\n";

                    $response = $client->get($workerUrl, [
                        'query'       => ['url' => $cleanUrl],
                        'http_errors' => false,
                        'timeout'     => 20,
                    ]);

                    $rawBody = (string)$response->getBody();
                    $data = json_decode($rawBody, true);

                    if (isset($data['code']) && $data['code'] === 0 && !empty($data['data'])) {
                        $item = $data['data'];

                        // 1. Обработка карусели фотографий
                        if (!empty($item['images']) && is_array($item['images'])) {
                            echo "Карусель из " . count($item['images']) . " фото. Отправка...\n";
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
                            echo "Фото доставлены.\n";
                            continue;
                        }

                        // 2. Обработка видео: скачиваем файл ботом и отправляем через multipart
                        $videoUrl = $item['play'] ?? null;
                        if ($videoUrl) {
                            echo "Загрузка видео с CDN для передачи в TG...\n";
                            $tempFile = tempnam(sys_get_temp_dir(), 'tt_vid_');
                            
                            $downloadSuccess = false;
                            try {
                                $client->get($videoUrl, [
                                    'sink'    => $tempFile,
                                    'headers' => [
                                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                                        'Referer'    => 'https://www.tiktok.com/',
                                    ],
                                    'timeout' => 40,
                                ]);
                                $downloadSuccess = (file_exists($tempFile) && filesize($tempFile) > 1000);
                            } catch (\Throwable $dlErr) {
                                echo "Ошибка скачивания видеофайла: " . $dlErr->getMessage() . "\n";
                            }

                            if ($downloadSuccess) {
                                echo "Видео скачано (" . filesize($tempFile) . " байт). Отправляю в Telegram...\n";
                                $client->post($telegramApiUrl . 'sendVideo', [
                                    'multipart' => [
                                        [
                                            'name'     => 'chat_id',
                                            'contents' => (string)$chatId,
                                        ],
                                        [
                                            'name'     => 'video',
                                            'contents' => fopen($tempFile, 'r'),
                                            'filename' => 'video.mp4',
                                        ],
                                        [
                                            'name'     => 'caption',
                                            'contents' => 'Скачано через @sfayzttbot',
                                        ],
                                        [
                                            'name'     => 'supports_streaming',
                                            'contents' => 'true',
                                        ],
                                    ],
                                ]);
                                @unlink($tempFile);
                                echo "Видео успешно доставлено.\n";
                                continue;
                            } else {
                                @unlink($tempFile);
                            }
                        }
                    }

                    echo "Ответ от воркера: {$rawBody}\n";
                    $client->post($telegramApiUrl . 'sendMessage', [
                        'json' => [
                            'chat_id' => $chatId,
                            'text'    => "Не удалось скачать медиа по этой ссылке.",
                        ],
                    ]);
                }
            }
        }
    } catch (\Throwable $e) {
        echo "Ошибка: " . $e->getMessage() . "\n";
        sleep(2);
    }

    usleep(500000);
}
