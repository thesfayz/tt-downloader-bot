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
echo "Бот запущен на прямом бесплатном API...\n";

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
            // Если предыдущий контейнер Render еще не завершился
            sleep(3);
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
                    $cleanUrl = isset($idMatches[1]) ? "https://www.tiktok.com/@i/video/{$idMatches[1]}" : strtok($tiktokUrl, '?');

                    echo "Парсинг URL: {$cleanUrl}\n";

                    $mediaData = null;

                    // Вариант 1: Прямой запрос к Lovetik (открытый API без ограничений Render)
                    try {
                        $res = $client->post('https://lovetik.com/api/ajax/search', [
                            'form_params' => ['query' => $cleanUrl],
                            'headers' => [
                                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36',
                                'Accept'     => 'application/json',
                            ],
                            'timeout' => 12,
                        ]);

                        $json = json_decode((string)$res->getBody(), true);

                        if (!empty($json['images']) && is_array($json['images'])) {
                            $mediaData = [
                                'type'   => 'photos',
                                'images' => $json['images'],
                            ];
                        } elseif (!empty($json['links'])) {
                            $videoUrl = null;
                            foreach ($json['links'] as $link) {
                                if (!empty($link['a']) && empty($link['watermark'])) {
                                    $videoUrl = $link['a'];
                                    break;
                                }
                            }
                            if (!$videoUrl && !empty($json['links'][0]['a'])) {
                                $videoUrl = $json['links'][0]['a'];
                            }

                            if ($videoUrl) {
                                $mediaData = [
                                    'type'  => 'video',
                                    'video' => $videoUrl,
                                ];
                            }
                        }
                    } catch (\Throwable $e) {
                        echo "Ошибка первичного API: " . $e->getMessage() . "\n";
                    }

                    // Отправка в Telegram
                    if ($mediaData) {
                        // Карусель картинок
                        if ($mediaData['type'] === 'photos' && !empty($mediaData['images'])) {
                            echo "Карусель из " . count($mediaData['images']) . " фото. Отправляю...\n";
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
                            echo "Фотографии доставлены.\n";
                            continue;
                        }

                        // Видеофайл
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

                    echo "Не удалось получить ссылки от API\n";
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
