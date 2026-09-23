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
    'timeout'         => 60.0,
    'allow_redirects' => true,
    'verify'          => false,
]);

$offset = 0;
echo "Бот yt-dlp (локальная выгрузка медиа) запущен...\n";

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
                            'text'    => "Обрабатываю ссылку, секунду...",
                        ],
                    ]);

                    // Разворачиваем короткие ссылки vt.tiktok.com
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

                    // Достаем ID поста
                    preg_match('/[\/](video|photo)[\/](\d+)/', $tiktokUrl, $idMatches);
                    $mediaId = $idMatches[2] ?? null;

                    $targetUrl = $mediaId ? "https://www.tiktok.com/@i/video/{$mediaId}" : strtok($tiktokUrl, '?');
                    $targetUrl = str_replace(' ', '%20', $targetUrl);

                    echo "Запуск обработки для: {$targetUrl}\n";

                    $tempDir = sys_get_temp_dir() . '/' . uniqid('tt_');
                    @mkdir($tempDir, 0777, true);

                    // Команда на выкачивание контента (и видео, и фото) на диск
                    $outputPattern = "{$tempDir}/%(autonumber)02d.%(ext)s";
                    $dlCmd = sprintf(
                        'yt-dlp --no-warnings --socket-timeout 20 -o %s %s 2>&1',
                        escapeshellarg($outputPattern),
                        escapeshellarg($targetUrl)
                    );

                    exec($dlCmd, $dlOut, $dlCode);

                    // Сканируем скачанные файлы
                    $files = glob("{$tempDir}/*");
                    $videoFiles = [];
                    $imageFiles = [];

                    foreach ($files as $file) {
                        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                        if (in_array($ext, ['mp4', 'mkv', 'webm'])) {
                            $videoFiles[] = $file;
                        } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                            $imageFiles[] = $file;
                        }
                    }

                    // 1. Если скачались изображения (карусель или одиночное фото)
                    if (!empty($imageFiles)) {
                        sort($imageFiles);

                        // Одиночное фото
                        if (count($imageFiles) === 1) {
                            echo "Отправка 1 фото...\n";
                            $client->post($telegramApiUrl . 'sendPhoto', [
                                'multipart' => [
                                    ['name' => 'chat_id', 'contents' => (string)$chatId],
                                    ['name' => 'photo',   'contents' => fopen($imageFiles[0], 'r'), 'filename' => 'photo.jpg'],
                                    ['name' => 'caption', 'contents' => 'Скачано через @sfayzttbot'],
                                ],
                            ]);
                        } else {
                            // Альбом фотографий (до 10 штук за раз)
                            echo "Отправка альбома из " . count($imageFiles) . " фото...\n";
                            $mediaGroup = [];
                            $multipart = [
                                ['name' => 'chat_id', 'contents' => (string)$chatId],
                            ];

                            $batch = array_slice($imageFiles, 0, 10);
                            foreach ($batch as $i => $imgPath) {
                                $attachName = "file_{$i}";
                                $multipart[] = [
                                    'name'     => $attachName,
                                    'contents' => fopen($imgPath, 'r'),
                                    'filename' => "photo_{$i}.jpg",
                                ];

                                $mediaGroup[] = [
                                    'type'    => 'photo',
                                    'media'   => "attach://{$attachName}",
                                    'caption' => ($i === 0) ? 'Скачано через @sfayzttbot' : '',
                                ];
                            }

                            $multipart[] = [
                                'name'     => 'media',
                                'contents' => json_encode($mediaGroup),
                            ];

                            $client->post($telegramApiUrl . 'sendMediaGroup', [
                                'multipart' => $multipart,
                            ]);
                        }

                        // Чистим временную папку
                        array_map('unlink', glob("{$tempDir}/*"));
                        @rmdir($tempDir);
                        echo "Фото успешно отправлены.\n";
                        continue;
                    }

                    // 2. Если скачалось видео
                    if (!empty($videoFiles) && filesize($videoFiles[0]) > 1000) {
                        echo "Видео скачано. Отправляю в Telegram...\n";
                        $client->post($telegramApiUrl . 'sendVideo', [
                            'multipart' => [
                                ['name' => 'chat_id',            'contents' => (string)$chatId],
                                ['name' => 'video',              'contents' => fopen($videoFiles[0], 'r'), 'filename' => 'video.mp4'],
                                ['name' => 'caption',            'contents' => 'Скачано через @sfayzttbot'],
                                ['name' => 'supports_streaming', 'contents' => 'true'],
                            ],
                        ]);

                        array_map('unlink', glob("{$tempDir}/*"));
                        @rmdir($tempDir);
                        echo "Видео успешно отправлено.\n";
                        continue;
                    }

                    // Если ничего не скачалось
                    array_map('unlink', glob("{$tempDir}/*"));
                    @rmdir($tempDir);

                    echo "Ошибка yt-dlp: " . implode(" | ", array_slice((array)$dlOut, -3)) . "\n";
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
