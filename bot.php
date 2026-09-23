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
echo "Бот на базе локального yt-dlp запущен...\n";

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

                    $cleanUrl = strtok($tiktokUrl, '?');
                    echo "Запуск yt-dlp для: {$cleanUrl}\n";

                    // 1. Получаем метаданные через yt-dlp
                    $dumpCmd = sprintf(
                        'yt-dlp -J --no-warnings --no-playlist %s 2>&1',
                        escapeshellarg($cleanUrl)
                    );

                    $jsonRaw = shell_exec($dumpCmd);
                    $info = json_decode($jsonRaw, true);

                    // 2. Если это фото-карусель (entries содержат картинки)
                    $imageUrls = [];
                    if (!empty($info['entries'])) {
                        foreach ($info['entries'] as $entry) {
                            if (!empty($entry['url'])) {
                                $imageUrls[] = $entry['url'];
                            }
                        }
                    }

                    if (!empty($imageUrls)) {
                        echo "Найдена фото-карусель из " . count($imageUrls) . " фото. Отправляю...\n";
                        $mediaGroup = [];
                        $photos = array_slice($imageUrls, 0, 10);
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

                    // 3. Если это видео: качаем без сжатия в исходном качестве
                    $tempDir = sys_get_temp_dir();
                    $filePrefix = uniqid('tt_');
                    $outputPattern = "{$tempDir}/{$filePrefix}.%(ext)s";

                    $dlCmd = sprintf(
                        'yt-dlp --no-warnings -f "bestvideo+bestaudio/best" --merge-output-format mp4 -o %s %s 2>&1',
                        escapeshellarg($outputPattern),
                        escapeshellarg($cleanUrl)
                    );

                    echo "Скачивание видео через yt-dlp...\n";
                    exec($dlCmd, $dlOut, $dlCode);

                    $videoPath = "{$tempDir}/{$filePrefix}.mp4";

                    if (file_exists($videoPath) && filesize($videoPath) > 1000) {
                        echo "Видео скачано (" . filesize($videoPath) . " байт). Отправляю в Telegram...\n";

                        $client->post($telegramApiUrl . 'sendVideo', [
                            'multipart' => [
                                [
                                    'name'     => 'chat_id',
                                    'contents' => (string)$chatId,
                                ],
                                [
                                    'name'     => 'video',
                                    'contents' => fopen($videoPath, 'r'),
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

                        @unlink($videoPath);
                        echo "Видео доставлено.\n";
                        continue;
                    } else {
                        @unlink($videoPath);
                        echo "Ошибка загрузки: " . implode(" | ", array_slice((array)$dlOut, -3)) . "\n";
                    }

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
