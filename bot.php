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
echo "Бот TikWM (прямой запуск) работает...\n";

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
                            'text'    => "Загружаю медиа без водяного знака...",
                        ],
                    ]);

                    // Разворачиваем редиректы коротких ссылок
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
                            echo "Ошибка разворота редиректа: " . $e->getMessage() . "\n";
                        }
                    }

                    // Достаем ID видео
                    preg_match('/\/video\/(\d+)/', $tiktokUrl, $idMatches);
                    $cleanUrl = isset($idMatches[1]) ? "https://www.tiktok.com/@i/video/{$idMatches[1]}" : strtok($tiktokUrl, '?');

                    echo "Парсинг через TikWM шлюз: {$cleanUrl}\n";

                    // Запрос напрямую к TikWM через белый шлюз, чтобы обойти 403 на Render
                    $gatewayUrl = 'https://corsproxy.io/?' . urlencode('https://www.tikwm.com/api/');

                    $response = $client->post($gatewayUrl, [
                        'form_params' => [
                            'url'   => $cleanUrl,
                            'count' => 12,
                            'cursor'=> 0,
                            'web'   => 1,
                            'hd'    => 1,
                        ],
                        'headers' => [
                            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/128.0.0.0 Safari/537.36',
                            'Accept'          => 'application/json, text/javascript, */*; q=0.01',
                            'X-Requested-With'=> 'XMLHttpRequest',
                        ],
                        'http_errors' => false,
                        'timeout'     => 15,
                    ]);

                    $rawBody = (string)$response->getBody();
                    $data = json_decode($rawBody, true);

                    if (isset($data['code']) && $data['code'] === 0 && !empty($data['data'])) {
                        $item = $data['data'];

                        // 1. Если это карусель картинок
                        if (!empty($item['images']) && is_array($item['images'])) {
                            echo "Карусель из " . count($item['images']) . " фото. Отправляю...\n";
                            $mediaGroup = [];
                            $photos = array_slice($item['images'], 0, 10);
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

                        // 2. Если это обычное видео
                        $videoUrl = $item['play'] ?? null;
                        if ($videoUrl) {
                            if (!str_starts_with($videoUrl, 'http')) {
                                $videoUrl = 'https://www.tikwm.com' . $videoUrl;
                            }

                            echo "Отправляю видео напрямую через CDN TikWM...\n";
                            $client->post($telegramApiUrl . 'sendVideo', [
                                'json' => [
                                    'chat_id'            => $chatId,
                                    'video'              => $videoUrl,
                                    'caption'            => 'Скачано через @sfayzttbot',
                                    'supports_streaming' => true,
                                ],
                            ]);
                            echo "Видео успешно отправлено.\n";
                            continue;
                        }
                    }

                    echo "Ответ от парсера: {$rawBody}\n";
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
