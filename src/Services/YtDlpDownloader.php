<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MediaDownloaderInterface;
use App\DTO\DownloadResult;
use App\DTO\MediaType;

final class YtDlpDownloader implements MediaDownloaderInterface
{
    public function __construct(
        private readonly int $socketTimeout = 20
    ) {}

    public function download(string $url): ?DownloadResult
    {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('tt_dl_', true);
        if (!@mkdir($tempDir, 0777, true)) {
            return null;
        }

        // 1. Резолвим короткую ссылку (vt.tiktok.com) только через заголовки (без скачивания тела)
        $fullUrl = $this->resolveRedirectOnly($url);

        // 2. Достаем ID видео
        $videoId = $this->extractVideoId($fullUrl);
        if ($videoId === null) {
            // Если ID не спарсился из URL, пробуем быстро достать его через легкий cURL
            $videoId = $this->fetchVideoIdFromWeb($fullUrl);
        }

        // 3. Загрузка через мобильный API TikTok (0 RAM, отдаёт чистый H264)
        if ($videoId !== null) {
            $result = $this->downloadViaAwemeApi($videoId, $tempDir);
            if ($result !== null) {
                return $result;
            }
        }

        // 4. Запасной быстрый фоллбэк: yt-dlp с жестким лимитом памяти и времени
        $videoPath = "{$tempDir}/video.mp4";
        $videoCmd = sprintf(
            'yt-dlp --no-warnings --no-playlist --socket-timeout %d -f "b[vcodec^=avc1]/bv*[vcodec^=avc1]+ba/b" --no-part -o %s %s 2>&1',
            $this->socketTimeout,
            escapeshellarg($videoPath),
            escapeshellarg($fullUrl)
        );
        exec($videoCmd);

        if (file_exists($videoPath) && filesize($videoPath) > 50000) {
            return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
        }

        (new DownloadResult(MediaType::UNKNOWN, [], $tempDir))->cleanup();
        return null;
    }

    private function downloadViaAwemeApi(string $videoId, string $tempDir): ?DownloadResult
    {
        $apiUrl = "https://api16-normal-c-useast1a.tiktokv.com/aweme/v1/feed/?aweme_id={$videoId}";

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'com.zhiliaoapp.musically/2022600030 (Linux; U; Android 12; en_US; Pixel 6)',
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $data = json_decode((string)$response, true);
        $item = $data['aweme_list'][0] ?? null;
        if (!is_array($item)) {
            return null;
        }

        // Проверяем: это карусель фоток?
        if (!empty($item['image_post_info']['images'])) {
            $images = [];
            foreach ($item['image_post_info']['images'] as $idx => $img) {
                $imgUrl = $img['display_image']['url_list'][0] ?? null;
                if (!$imgUrl) {
                    continue;
                }

                $target = sprintf('%s/slide_%02d.jpg', $tempDir, $idx + 1);
                if ($this->streamDownloadToFile($imgUrl, $target)) {
                    $images[] = $target;
                }
            }

            if (!empty($images)) {
                return new DownloadResult(MediaType::CAROUSEL, $images, $tempDir);
            }
        }

        // Это видео: достаем прямой MP4 (без водяных знаков)
        $videoUrls = $item['video']['play_addr']['url_list'] ?? [];
        if (empty($videoUrls)) {
            return null;
        }

        $videoPath = "{$tempDir}/video.mp4";
        foreach ($videoUrls as $streamUrl) {
            if ($this->streamDownloadToFile($streamUrl, $videoPath)) {
                return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
            }
        }

        return null;
    }

    /**
     * Потоковое скачивание сразу на диск (не жрёт оперативную память PHP)
     */
    private function streamDownloadToFile(string $sourceUrl, string $destinationPath): bool
    {
        $fp = fopen($destinationPath, 'w+');
        if (!$fp) {
            return false;
        }

        $ch = curl_init($sourceUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'com.zhiliaoapp.musically/2022600030 (Linux; U; Android 12; en_US; Pixel 6)',
            CURLOPT_HTTPHEADER     => [
                'Referer: https://www.tiktok.com/',
            ],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($code === 200 && file_exists($destinationPath) && filesize($destinationPath) > 5000) {
            return true;
        }

        if (file_exists($destinationPath)) {
            @unlink($destinationPath);
        }

        return false;
    }

    /**
     * Быстрый резолв коротких ссылок HEAD-запросом без скачивания тела страницы
     */
    private function resolveRedirectOnly(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ]);
        curl_exec($ch);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return $effectiveUrl ?: $url;
    }

    private function extractVideoId(string $url): ?string
    {
        if (preg_match('/video\/(\d+)/', $url, $m)) {
            return $m[1];
        }
        if (preg_match('/\/v\/(\d+)/', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    private function fetchVideoIdFromWeb(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_RANGE          => '0-40960', // читаем только первые 40 КБ, чтобы не тратить память
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ]);
        $chunk = (string)curl_exec($ch);
        curl_close($ch);

        if (preg_match('/"videoId":"(\d+)"/', $chunk, $m)) {
            return $m[1];
        }
        return null;
    }
}
