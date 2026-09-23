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
echo "Бот yt-dlp (полноценные фотопосты + видео) запущен...\n";

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
                            'text'    => "Отправь ссылку на TikTok (видео или фото-карусель), и я скачаю всё без водяного знака.",
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

                    // Извлекаем ID
                    preg_match('/[\/](video|photo)[\/](\d+)/', $tiktokUrl, $idMatches);
                    $mediaId = $idMatches[2] ?? null;

                    $targetUrl = $mediaId ? "https://www.tiktok.com/@i/video/{$mediaId}" : strtok($tiktokUrl, '?');
                    $targetUrl = str_replace(' ', '%20', $targetUrl);

                    echo "Получение метаданных: {$targetUrl}\n";

                    // 1. Получаем JSON с информацией о посте
                    $dumpCmd = sprintf(
                        'yt-dlp -J --no-warnings --socket-timeout 20 %s 2>&1',
                        escapeshellarg($targetUrl)
                    );

                    $jsonRaw = shell_exec($dumpCmd);
                    $info = json_decode($jsonRaw, true);

                    // Проверяем наличие картинок слайдшоу в thumbnails
                    $slideImages = [];
                    if (!empty($info['thumbnails']) && is_array($info['thumbnails'])) {
                        foreach ($info['thumbnails'] as $t) {
                            // Ищем именно кадры слайдшоу (обычно id = image_0, image_1...)
                            if (isset($t['id']) && str_starts_with($t['id'], 'image_') && !empty($t['url'])) {
                                $slideImages[] = $t['url'];
                            }
                        }
                    }

                    // Если картинок слайдшоу больше одной — это фотопост!
                    if (!empty($slideImages)) {
                        echo "Найдено " . count($slideImages) . " слайдов фото. Скачиваю...\n";
                        $tempDir = sys_get_temp_dir() . '/' . uniqid('tt_img_');
                        @mkdir($tempDir, 0777, true);

                        $downloadedFiles = [];
                        foreach (array_slice($slideImages, 0, 10) as $idx => $imgUrl) {
                            $filePath = "{$tempDir}/photo_{$idx}.jpg";
                            try {
                                $client->get($imgUrl, ['sink' => $filePath, 'timeout' => 10]);
                                if (file_exists($filePath) && filesize($filePath) > 1000) {
                                    $downloadedFiles[] = $filePath;
                                }
                            } catch (\Throwable $e) {}
                        }

                        if (!empty($downloadedFiles)) {
                            echo "Отправляю " . count($downloadedFiles) . " фото в чат...\n";

                            if (count($downloadedFiles) === 1) {
                                $client->post($telegramApiUrl . 'sendPhoto', [
                                    'multipart' => [
                                        ['name' => 'chat_id', 'contents' => (string)$chatId],
                                        ['name' => 'photo',   'contents' => fopen($downloadedFiles[0], 'r'), 'filename' => 'photo.jpg'],
                                        ['name' => 'caption', 'contents' => 'Скачано через @sfayzttbot'],
                                    ],
                                ]);
                            } else {
                                $mediaGroup = [];
                                $multipart = [
                                    ['name' => 'chat_id', 'contents' => (string)$chatId],
                                ];

                                foreach ($downloadedFiles as $i => $path) {
                                    $attachName = "photo_{$i}";
                                    $multipart[] = [
                                        'name'     => $attachName,
                                        'contents' => fopen($path, 'r'),
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

                            array_map('unlink', glob("{$tempDir}/*"));
                            @rmdir($tempDir);
                            echo "Фотографии успешно отправлены.\n";
                            continue;
                        }

                        array_map('unlink', glob("{$tempDir}/*"));
                        @rmdir($tempDir);
                    }

                    // 2. Если это видеопост — скачиваем полноценное MP4
                    $tempDir = sys_get_temp_dir();
                    $filePrefix = uniqid('tt_vid_');
                    $videoPath = "{$tempDir}/{$filePrefix}.mp4";

                    $dlCmd = sprintf(
                        'yt-dlp --no-warnings -f "bv*+ba/b" --merge-output-format mp4 -o %s %s 2>&1',
                        escapeshellarg($videoPath),
                        escapeshellarg($targetUrl)
                    );

                    echo "Скачивание видео через yt-dlp...\n";
                    exec($dlCmd, $dlOut, $dlCode);

                    if (file_exists($videoPath) && filesize($videoPath) > 5000) {
                        echo "Видео скачано. Отправляю в Telegram...\n";
                        $client->post($telegramApiUrl . 'sendVideo', [
                            'multipart' => [
                                ['name' => 'chat_id',            'contents' => (string)$chatId],
                                ['name' => 'video',              'contents' => fopen($videoPath, 'r'), 'filename' => 'video.mp4'],
                                ['name' => 'caption',            'contents' => 'Скачано через @sfayzttbot'],
                                ['name' => 'supports_streaming', 'contents' => 'true'],
                            ],
                        ]);

                        @unlink($videoPath);
                        echo "Видео доставлено.\n";
                        continue;
                    }

                    @unlink($videoPath);
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
