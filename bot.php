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
                            'text'    => "Отправь ссылку на TikTok, и я пришлю видео без водяного знака.",
                        ],
                    ]);
                    continue;
                }

                if (preg_match('/https?:\/\/[^\s]+/', $text, $matches)) {
                    $tiktokUrl = $matches[0];

                    $client->post($telegramApiUrl . 'sendMessage', [
                        'json' => [
                            'chat_id' => $chatId,
                            'text'    => "Скачиваю без водяного знака, подожди немного...",
                        ],
                    ]);

                    $fileId = uniqid('tt_');
                    $outputPath = __DIR__ . "/downloads/{$fileId}.mp4";

                    // Команда вызова yt-dlp
                    $cmd = sprintf(
                        'yt-dlp --no-warnings -f "best[ext=mp4]/best" -o %s %s 2>&1',
                        escapeshellarg($outputPath),
                        escapeshellarg($tiktokUrl)
                    );

                    echo "Выполняю команду...\n";
                    exec($cmd, $output, $returnCode);

                    if ($returnCode === 0 && file_exists($outputPath)) {
                        echo "Видео скачано локально. Отправка в Telegram...\n";

                        $client->post($telegramApiUrl . 'sendVideo', [
                            'multipart' => [
                                [
                                    'name'     => 'chat_id',
                                    'contents' => (string)$chatId,
                                ],
                                [
                                    'name'     => 'video',
                                    'contents' => fopen($outputPath, 'r'),
                                    'filename' => 'video.mp4',
                                ],
                                [
                                    'name'     => 'caption',
                                    'contents' => 'Скачано через @sfayzttbot',
                                ],
                            ],
                        ]);

                        unlink($outputPath); // Удаляем временный файл
                        echo "Файл отправлен и удален.\n";
                    } else {
                        echo "Ошибка yt-dlp: " . implode("\n", $output) . "\n";
                        $client->post($telegramApiUrl . 'sendMessage', [
                            'json' => [
                                'chat_id' => $chatId,
                                'text'    => "Не удалось скачать видео через.",
                            ],
                        ]);
                    }
                }
            }
        }
    } catch (\Throwable $e) {
        echo "Ошибка: " . $e->getMessage() . "\n";
        sleep(2);
    }

    usleep(500000);
}
