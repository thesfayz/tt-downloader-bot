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
    'verify'          => false,
]);

$offset = 0;
echo "Бот запущен...\n";

while (true) {
    try {
        $updatesResponse = $client->get($telegramApiUrl . 'getUpdates', [
            'query' => [
                'offset'  => $offset,
                'timeout' => 20,
            ],
            'http_errors' => false,
        ]);

        if ($updatesResponse->getStatusCode() === 409) {
            sleep(2);
            continue;
        }

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
                            'text'    => "Отправь ссылку на TikTok (видео или фото-карусель), и я пришлю всё без водяного знака.",
                        ],
                    ]);
                    continue;
                }

                if (preg_match('/https?:\/\/[^\s]+/', $text, $matches)) {
                    $tiktokUrl = $matches[0];

                    $client->post($telegramApiUrl . 'sendMessage', [
                        'json' => [
                            'chat_id' => $chatId,
                            'text'    => "Загружаю медиа...",
                        ],
                    ]);

                    // 1. Разворачиваем короткие ссылки
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

                    $cleanUrl = strtok($tiktokUrl, '?');
                    echo "Запрос для URL: {$cleanUrl}\n";

                    $mediaData = null;

                    // 2. Запрос к TikMate API (работает из любых дата-центров)
                    try {
                        $res = $client->post('https://api.tikmate.app/api/lookup', [
                            'form_params' => [
                                'url' => $cleanUrl,
                            ],
                            'headers' => [
                                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                            ],
                            'timeout' => 15,
                        ]);

                        $data = json_decode((string)$res->getBody(), true);

                        if (!empty($data['success'])) {
                            // Формируем прямую ссылку на видео без водяного знака через шлюз TikMate
                            $token = $data['token'] ?? null;
                            $videoId = $data['id'] ?? null;

                            if ($token && $videoId) {
                                $mediaData = [
                                    'type'  => 'video',
                                    'video' => "https://tikmate.app/download/{$token}/{$videoId}.mp4?hd=1",
                                ];
                            }
                        }
                    } catch (\Throwable $e) {
                        echo "TikMate сбой: " . $e->getMessage() . "\n";
                    }

                    // 3. Если это фото-карусель или TikMate не сработал — резервный парсер
                    if (!$mediaData) {
                        try {
                            $backupRes = $client->get('https://widipe.com/download/tiktok', [
                                'query' => ['url' => $cleanUrl],
                                'timeout' => 15,
                            ]);

                            $bData = json_decode((string)$backupRes->getBody(), true);
                            if (!empty($bData['result']['images']) && is_array($bData['result']['images'])) {
                                $mediaData = [
                                    'type'   => 'photos',
                                    'images' => $bData['result']['images'],
                                ];
                            } elseif (!empty($bData['result']['video'])) {
                                $mediaData = [
                                    'type'  => 'video',
                                    'video' => $bData['result']['video'],
                                ];
                            }
                        } catch (\Throwable $e) {
                            echo "Резерв сбой: " . $e->getMessage() . "\n";
                        }
                    }

                    // 4. Отправка в Telegram
                    if ($mediaData) {
                        // Фото-карусель
                        if ($mediaData['type'] === 'photos' && !empty($mediaData['images'])) {
                            echo "Карусель из " . count($mediaData['images']) . " фото. Отправка...\n";
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

                        // Видео (прямая ссылка)
                        if ($mediaData['type'] === 'video' && !empty($mediaData['video'])) {
                            echo "Отправка видео напрямую в Telegram...\n";
                            $client->post($telegramApiUrl . 'sendVideo', [
                                'json' => [
                                    'chat_id'            => $chatId,
                                    'video'              => $mediaData['video'],
                                    'caption'            => 'Скачано через @sfayzttbot',
                                    'supports_streaming' => true,
                                ],
                            ]);
                            echo "Видео успешно доставлено.\n";
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
        echo "Ошибка в цикле: " . $e->getMessage() . "\n";
        sleep(2);
    }

    usleep(500000);
}
