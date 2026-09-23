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
    exit("Ошибка: Токен бота не найден\n");
}

$telegramApiUrl = "https://api.telegram.org/bot{$botToken}/";

$client = new Client([
    'timeout'         => 30.0,
    'allow_redirects' => true,
]);

$offset = 0;
echo "Бот TikWM (HD видео + фото-карусели) запущен...\n";

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

                    // 1. Разворачиваем короткие ссылки vt.tiktok.com / vm.tiktok.com
                    if (str_contains($tiktokUrl, 'vt.tiktok.com') || str_contains($tiktokUrl, 'vm.tiktok.com')) {
                        try {
                            $redirectResponse = $client->get($tiktokUrl, [
                                'allow_redirects' => [
                                    'max'             => 10,
                                    'track_redirects' => true,
                                ],
                                'headers' => [
                                    'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
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

                    // Чистим query-параметры и нормализуем пробелы/спецсимволы
                    $cleanUrl = strtok($tiktokUrl, '?');
                    $cleanUrl = str_replace(' ', '%20', $cleanUrl);
                    echo "Парсинг URL: {$cleanUrl}\n";

                    $mediaData = null;

                    // 2. Основной запрос: оригинальный TikWM API
                    try {
                        $tikwmResponse = $client->post('https://www.tikwm.com/api/', [
                            'form_params' => [
                                'url'   => $cleanUrl,
                                'count' => 12,
                                'cursor'=> 0,
                                'web'   => 1,
                                'hd'    => 1,
                            ],
                            'headers' => [
                                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                                'Accept'          => 'application/json, text/javascript, */*; q=0.01',
                                'X-Requested-With'=> 'XMLHttpRequest',
                                'Origin'          => 'https://www.tikwm.com',
                                'Referer'         => 'https://www.tikwm.com/',
                            ],
                            'http_errors' => false,
                            'timeout'     => 12,
                        ]);

                        $body = (string)$tikwmResponse->getBody();
                        $json = json_decode($body, true);

                        if (isset($json['code']) && $json['code'] === 0 && !empty($json['data'])) {
                            $mediaData = [
                                'type'   => !empty($json['data']['images']) ? 'photos' : 'video',
                                'images' => $json['data']['images'] ?? [],
                                'video'  => $json['data']['play'] ?? null,
                            ];
                            echo "Успешно получено от TikWM\n";
                        } else {
                            echo "TikWM вернул ошибку, пробую резервный API...\n";
                        }
                    } catch (\Throwable $e) {
                        echo "Сбой TikWM: " . $e->getMessage() . "\n";
                    }

                    // 3. Резервный открытый API (если TikWM временно отдал капчу/блок)
                    if (!$mediaData) {
                        try {
                            $backupResponse = $client->get('https://api.tiklydown.eu.org/api/download', [
                                'query' => ['url' => $cleanUrl],
                                'headers' => [
                                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36',
                                ],
                                'http_errors' => false,
                                'timeout'     => 12,
                            ]);

                            $bJson = json_decode((string)$backupResponse->getBody(), true);
                            if (!empty($bJson['images'])) {
                                $mediaData = [
                                    'type'   => 'photos',
                                    'images' => array_column($bJson['images'], 'url'),
                                ];
                            } elseif (!empty($bJson['video']['noWatermark'])) {
                                $mediaData = [
                                    'type'  => 'video',
                                    'video' => $bJson['video']['noWatermark'],
                                ];
                            }
                        } catch (\Throwable $e) {
                            echo "Сбой резервного API: " . $e->getMessage() . "\n";
                        }
                    }

                    // 4. Отправка результата в чат
                    if ($mediaData) {
                        // АЛЬБОМ ФОТОГРАФИЙ
                        if ($mediaData['type'] === 'photos' && !empty($mediaData['images'])) {
                            echo "Отправляю " . count($mediaData['images']) . " фото...\n";
                            $mediaGroup = [];
                            $photos = array_slice($mediaData['images'], 0, 10);
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

                        // ВИДЕО БЕЗ ВОДЯНОГО ЗНАКА
                        if ($mediaData['type'] === 'video' && !empty($mediaData['video'])) {
                            $videoUrl = $mediaData['video'];
                            if (!str_starts_with($videoUrl, 'http')) {
                                $videoUrl = 'https://www.tikwm.com' . $videoUrl;
                            }

                            echo "Отправляю видео напрямую через CDN...\n";
                            $client->post($telegramApiUrl . 'sendVideo', [
                                'json' => [
                                    'chat_id'            => $chatId,
                                    'video'              => $videoUrl,
                                    'caption'            => 'Скачано через @sfayzttbot',
                                    'supports_streaming' => true,
                                ],
                            ]);
                            echo "Видео доставлено.\n";
                            continue;
                        }
                    }

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
