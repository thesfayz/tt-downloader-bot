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
                            'text'    => "Отправь ссылку на TikTok, и я пришлю всё без водяного знака.",
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

                    // Разворачиваем ссылки
                    if (str_contains($tiktokUrl, 'vt.tiktok.com') || str_contains($tiktokUrl, 'vm.tiktok.com')) {
                        try {
                            $redirectResponse = $client->get($tiktokUrl, [
                                'allow_redirects' => ['max' => 10, 'track_redirects' => true],
                                'headers' => ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'],
                            ]);
                            $history = $redirectResponse->getHeader('X-Guzzle-Redirect-History');
                            if (!empty($history)) {
                                $tiktokUrl = end($history);
                            }
                        } catch (\Throwable $e) {}
                    }

                    preg_match('/[\/](video|photo)[\/](\d+)/', $tiktokUrl, $idMatches);
                    $mediaId = $idMatches[2] ?? null;
                    $targetUrl = $mediaId ? "https://www.tiktok.com/@i/video/{$mediaId}" : strtok($tiktokUrl, '?');
                    $targetUrl = str_replace(' ', '%20', $targetUrl);

                    echo "Анализ медиа: {$targetUrl}\n";

                    // 1. Получаем JSON метаданных
                    $dumpCmd = sprintf(
                        'yt-dlp -J --no-warnings --socket-timeout 20 %s 2>&1',
                        escapeshellarg($targetUrl)
                    );
                    $jsonRaw = shell_exec($dumpCmd);
                    $info = json_decode($jsonRaw, true);

                    // 2. Ищем картинки фото-карусели
                    $photos = [];

                    // А) Если это плейлист слайдов в entries
                    if (!empty($info['entries']) && is_array($info['entries'])) {
                        foreach ($info['entries'] as $entry) {
                            if (!empty($entry['url'])) {
                                $photos[] = $entry['url'];
                            }
                        }
                    }

                    // Б) Если картинки лежат в thumbnails самого объекта
                    if (empty($photos) && !empty($info['thumbnails']) && is_array($info['thumbnails'])) {
                        foreach ($info['thumbnails'] as $t) {
                            // Берем только уникальные полные фото поста
                            if (isset($t['id']) && preg_match('/^(image_\d+|preview_\d+)$/', $t['id']) && !empty($t['url'])) {
                                $photos[] = $t['url'];
                            }
                        }
                    }

                    // В) Если в formats лежат только аудио или duration == 0, но есть список thumbnails
                    $isVideo = !empty($info['duration']) && $info['duration'] > 0;
                    if (!$isVideo && empty($photos) && !empty($info['thumbnails'])) {
                        foreach ($info['thumbnails'] as $t) {
                            if (!empty($t['url']) && !str_contains($t['url'], 'avatar')) {
                                $photos[] = $t['url'];
                            }
                        }
                        $photos = array_unique($photos);
                    }

                    // 3. ОТПРАВКА ФОТО (если найдены картинки или это не видео)
                    if (!empty($photos) && (!$isVideo || count($photos) > 1)) {
                        echo "Найдено " . count($photos) . " картинок. Скачиваю для отправки...\n";
                        $tempDir = sys_get_temp_dir() . '/' . uniqid('tt_pics_');
                        @mkdir($tempDir, 0777, true);

                        $downloaded = [];
                        foreach (array_slice($photos, 0, 10) as $i => $imgUrl) {
                            $filePath = "{$tempDir}/p_{$i}.jpg";
                            try {
                                $client->get($imgUrl, ['sink' => $filePath, 'timeout' => 10]);
                                if (file_exists($filePath) && filesize($filePath) > 1000) {
                                    $downloaded[] = $filePath;
                                }
                            } catch (\Throwable $e) {}
                        }

                        if (!empty($downloaded)) {
                            if (count($downloaded) === 1) {
                                $client->post($telegramApiUrl . 'sendPhoto', [
                                    'multipart' => [
                                        ['name' => 'chat_id', 'contents' => (string)$chatId],
                                        ['name' => 'photo',   'contents' => fopen($downloaded[0], 'r'), 'filename' => 'photo.jpg'],
                                        ['name' => 'caption', 'contents' => 'Скачано через @sfayzttbot'],
                                    ],
                                ]);
                            } else {
                                $mediaGroup = [];
                                $multipart = [
                                    ['name' => 'chat_id', 'contents' => (string)$chatId],
                                ];

                                foreach ($downloaded as $i => $path) {
                                    $attachName = "file_{$i}";
                                    $multipart[] = [
                                        'name'     => $attachName,
                                        'contents' => fopen($path, 'r'),
                                        'filename' => "p_{$i}.jpg",
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
                            echo "Фотографии отправлены.\n";
                            continue;
                        }

                        array_map('unlink', glob("{$tempDir}/*"));
                        @rmdir($tempDir);
                    }

                    // 4. ОТПРАВКА НАСТОЯЩЕГО ВИДЕО
                    $tempDir = sys_get_temp_dir();
                    $filePrefix = uniqid('tt_vid_');
                    $videoPath = "{$tempDir}/{$filePrefix}.mp4";

                    // Требуем именно видеопоток (-f "bv*+ba/b"), чтобы не качать пустые m4a
                    $dlCmd = sprintf(
                        'yt-dlp --no-warnings -f "bv*[ext=mp4]+ba[ext=m4a]/b[ext=mp4]/best" --merge-output-format mp4 -o %s %s 2>&1',
                        escapeshellarg($videoPath),
                        escapeshellarg($targetUrl)
                    );

                    echo "Скачивание видеопотока...\n";
                    exec($dlCmd, $dlOut, $dlCode);

                    if (file_exists($videoPath) && filesize($videoPath) > 50000) {
                        echo "Видео скачано (" . filesize($videoPath) . " байт). Отправляю...\n";
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
                            'text'    => "Не удалось загрузить медиа по этой ссылке.",
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
