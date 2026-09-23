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
                            'text'    => "Загружаю, подожди пару секунд...",
                        ],
                    ]);

                    // Разворачиваем короткие ссылки vt/vm
                    if (str_contains($tiktokUrl, 'vt.tiktok.com') || str_contains($tiktokUrl, 'vm.tiktok.com')) {
                        try {
                            $redirectRes = $client->get($tiktokUrl, [
                                'allow_redirects' => ['max' => 10, 'track_redirects' => true],
                                'headers' => ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'],
                            ]);
                            $history = $redirectRes->getHeader('X-Guzzle-Redirect-History');
                            if (!empty($history)) {
                                $tiktokUrl = end($history);
                            }
                        } catch (\Throwable $e) {}
                    }

                    // Нормализуем URL по ID
                    preg_match('/[\/](video|photo)[\/](\d+)/', $tiktokUrl, $idMatches);
                    $mediaType = $idMatches[1] ?? 'video';
                    $mediaId   = $idMatches[2] ?? null;

                    $targetUrl = $mediaId ? "https://www.tiktok.com/@i/video/{$mediaId}" : strtok($tiktokUrl, '?');
                    $targetUrl = str_replace(' ', '%20', $targetUrl);

                    echo "Обработка: {$targetUrl} (Тип: {$mediaType})\n";

                    $tempDir = sys_get_temp_dir() . '/' . uniqid('tt_');
                    @mkdir($tempDir, 0777, true);

                    // ВЕТКА 1: ЭТО ФОТОПОСТ
                    if ($mediaType === 'photo' || str_contains($tiktokUrl, '/photo/')) {
                        echo "Скачивание фото-слайдов через yt-dlp...\n";
                        
                        $imgPattern = "{$tempDir}/img_%(autonumber)02d.%(ext)s";
                        $cmd = sprintf(
                            'yt-dlp --no-warnings --write-all-thumbnails --skip-download -o %s %s 2>&1',
                            escapeshellarg($imgPattern),
                            escapeshellarg($targetUrl)
                        );
                        exec($cmd);

                        // Собираем скачанные изображения
                        $images = glob("{$tempDir}/*.{jpg,jpeg,png,webp}", GLOB_BRACE);

                        // Фильтруем слишком маленькие файлы (аватарки/иконки)
                        $validImages = [];
                        foreach ($images as $img) {
                            if (filesize($img) > 10000) { // больше 10 КБ
                                $validImages[] = $img;
                            }
                        }
                        sort($validImages);

                        if (!empty($validImages)) {
                            if (count($validImages) === 1) {
                                echo "Отправка 1 фото...\n";
                                $client->post($telegramApiUrl . 'sendPhoto', [
                                    'multipart' => [
                                        ['name' => 'chat_id', 'contents' => (string)$chatId],
                                        ['name' => 'photo',   'contents' => fopen($validImages[0], 'r'), 'filename' => 'photo.jpg'],
                                        ['name' => 'caption', 'contents' => 'Скачано через @sfayzttbot'],
                                    ],
                                ]);
                            } else {
                                echo "Отправка карусели из " . count($validImages) . " фото...\n";
                                $mediaGroup = [];
                                $multipart = [
                                    ['name' => 'chat_id', 'contents' => (string)$chatId],
                                ];

                                foreach (array_slice($validImages, 0, 10) as $i => $path) {
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
                            echo "Фото доставлены.\n";
                            continue;
                        }
                    }

                    // ВЕТКА 2: ЭТО ВИДЕОПОСТ
                    echo "Скачивание видео через yt-dlp...\n";
                    $videoPath = "{$tempDir}/video.mp4";

                    $videoCmd = sprintf(
                        'yt-dlp --no-warnings -f "bv*[vcodec!=none]+ba/b[vcodec!=none]" --merge-output-format mp4 -o %s %s 2>&1',
                        escapeshellarg($videoPath),
                        escapeshellarg($targetUrl)
                    );
                    exec($videoCmd, $vOut, $vCode);

                    if (file_exists($videoPath) && filesize($videoPath) > 50000) {
                        echo "Видео скачано. Отправляю в Telegram...\n";
                        $client->post($telegramApiUrl . 'sendVideo', [
                            'multipart' => [
                                ['name' => 'chat_id',            'contents' => (string)$chatId],
                                ['name' => 'video',              'contents' => fopen($videoPath, 'r'), 'filename' => 'video.mp4'],
                                ['name' => 'caption',            'contents' => 'Скачано через @sfayzttbot'],
                                ['name' => 'supports_streaming', 'contents' => 'true'],
                            ],
                        ]);

                        array_map('unlink', glob("{$tempDir}/*"));
                        @rmdir($tempDir);
                        echo "Видео доставлено.\n";
                        continue;
                    }

                    array_map('unlink', glob("{$tempDir}/*"));
                    @rmdir($tempDir);

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
