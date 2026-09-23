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
echo "Бот запущен (прямой парсинг TikTok JSON)...\n";

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
                            'text'    => "Загружаю, подожди пару секунд...",
                        ],
                    ]);

                    // Разворачиваем мобильные ссылки vt/vm
                    if (str_contains($tiktokUrl, 'vt.tiktok.com') || str_contains($tiktokUrl, 'vm.tiktok.com')) {
                        try {
                            $redirectRes = $client->get($tiktokUrl, [
                                'allow_redirects' => ['max' => 10, 'track_redirects' => true],
                                'headers' => ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36'],
                            ]);
                            $history = $redirectRes->getHeader('X-Guzzle-Redirect-History');
                            if (!empty($history)) {
                                $tiktokUrl = end($history);
                            }
                        } catch (\Throwable $e) {}
                    }

                    $cleanUrl = strtok($tiktokUrl, '?');
                    echo "Запрос HTML страницы: {$cleanUrl}\n";

                    // 1. Забираем исходный код страницы TikTok через curl прямо с контейнера
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL            => $cleanUrl,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_TIMEOUT        => 20,
                        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                        CURLOPT_HTTPHEADER     => [
                            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                            'Accept-Language: en-US,en;q=0.9',
                        ],
                    ]);
                    $html = curl_exec($ch);
                    curl_close($ch);

                    // 2. Достаем встроенный JSON объект
                    $itemStruct = null;

                    if (preg_match('/<script id="__UNIVERSAL_DATA_FOR_REHYDRATION__"[^>]*>(.*?)<\/script>/s', $html, $m)) {
                        $parsed = json_decode($m[1], true);
                        $defaultScope = $parsed['__DEFAULT_SCOPE__'] ?? [];
                        $itemStruct = $defaultScope['webapp.video-detail']['itemInfo']['itemStruct'] ?? null;
                    }

                    if (!$itemStruct && preg_match('/<script id="SIGI_STATE"[^>]*>(.*?)<\/script>/s', $html, $m)) {
                        $parsed = json_decode($m[1], true);
                        $itemModule = $parsed['ItemModule'] ?? [];
                        $firstKey = array_key_first($itemModule);
                        $itemStruct = $itemModule[$firstKey] ?? null;
                    }

                    // 3. ПРОВЕРКА НА КАРУСЕЛЬ ФОТО
                    $photos = [];
                    if (!empty($itemStruct['imagePost']['images'])) {
                        foreach ($itemStruct['imagePost']['images'] as $img) {
                            $imgUrl = $img['displayImage']['urlList'][0] ?? ($img['imageURL']['urlList'][0] ?? null);
                            if ($imgUrl) {
                                $photos[] = $imgUrl;
                            }
                        }
                    }

                    if (!empty($photos)) {
                        echo "Найдено " . count($photos) . " фото. Скачиваю оригинал...\n";
                        $tempDir = sys_get_temp_dir() . '/' . uniqid('tt_img_');
                        @mkdir($tempDir, 0777, true);

                        $downloaded = [];
                        foreach (array_slice($photos, 0, 10) as $i => $imgUrl) {
                            $filePath = "{$tempDir}/p_{$i}.jpg";
                            try {
                                $client->get($imgUrl, ['sink' => $filePath, 'timeout' => 15]);
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
                            echo "Фото успешно отправлены.\n";
                            continue;
                        }

                        array_map('unlink', glob("{$tempDir}/*"));
                        @rmdir($tempDir);
                    }

                    // 4. ЕСЛИ ЭТО ВИДЕО (скачиваем через yt-dlp)
                    echo "Это видео, запускаю скачивание через yt-dlp...\n";
                    $tempDir = sys_get_temp_dir();
                    $filePrefix = uniqid('tt_vid_');
                    $videoPath = "{$tempDir}/{$filePrefix}.mp4";

                    $dlCmd = sprintf(
                        'yt-dlp --no-warnings -f "bv*[vcodec!=none]+ba/b[vcodec!=none]" --merge-output-format mp4 -o %s %s 2>&1',
                        escapeshellarg($videoPath),
                        escapeshellarg($cleanUrl)
                    );

                    exec($dlCmd, $dlOut, $dlCode);

                    if (file_exists($videoPath) && filesize($videoPath) > 50000) {
                        echo "Видео скачано (" . filesize($videoPath) . " байт). Отправляю в Telegram...\n";
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
