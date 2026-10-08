<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MediaDownloaderInterface;
use App\DTO\DownloadResult;
use App\DTO\MediaType;

final class YtDlpDownloader implements MediaDownloaderInterface
{
    public function __construct(
        private readonly int $socketTimeout = 30
    ) {}

    public function download(string $url): ?DownloadResult
    {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('tt_dl_', true);
        if (!@mkdir($tempDir, 0777, true)) {
            return null;
        }

        // Шаг 1: Пробуем быстрые внешние API без водяных знаков и без нагрузки на CPU/RAM
        $apiResult = $this->tryExternalApis($url, $tempDir);
        if ($apiResult !== null) {
            return $apiResult;
        }

        // Шаг 2: Фоллбэк на yt-dlp с жестким ограничением формата MP4 (H264)
        $videoPath = "{$tempDir}/video.mp4";
        $cmd = sprintf(
            'yt-dlp --no-warnings --no-playlist --socket-timeout %d -f "b[ext=mp4]/bestvideo[ext=mp4]+bestaudio[ext=m4a]/best" --no-part -o %s %s 2>&1',
            $this->socketTimeout,
            escapeshellarg($videoPath),
            escapeshellarg($url)
        );
        exec($cmd);

        if (file_exists($videoPath) && filesize($videoPath) > 100000) {
            return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
        }

        (new DownloadResult(MediaType::UNKNOWN, [], $tempDir))->cleanup();
        return null;
    }

    private function tryExternalApis(string $url, string $tempDir): ?DownloadResult
    {
        // 1. Попытка через Tiklydown API (отлично ест короткие и полные ссылки, отдает h264)
        $result = $this->tryTiklydown($url, $tempDir);
        if ($result !== null) {
            return $result;
        }

        // 2. Попытка через TikWM (через JSON эндпоинт с маскировкой под мобильный клиент)
        $result = $this->tryTikWm($url, $tempDir);
        if ($result !== null) {
            return $result;
        }

        return null;
    }

    private function tryTiklydown(string $url, string $tempDir): ?DownloadResult
    {
        $apiUrl = 'https://api.tiklydown.eu.org/api/download?url=' . urlencode($url);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return null;
        }

        // Фото / карусель
        if (!empty($data['images']) && is_array($data['images'])) {
            $images = [];
            foreach ($data['images'] as $idx => $img) {
                $imgUrl = is_array($img) ? ($img['url'] ?? null) : $img;
                if (!$imgUrl) {
                    continue;
                }

                $target = sprintf('%s/slide_%02d.jpg', $tempDir, $idx + 1);
                if ($this->streamToFile($imgUrl, $target)) {
                    $images[] = $target;
                }
            }

            if (!empty($images)) {
                return new DownloadResult(MediaType::CAROUSEL, $images, $tempDir);
            }
        }

        // Видео
        $videoUrl = $data['video']['noWatermark']
            ?? $data['video']['watermark']
            ?? ($data['video']['url'] ?? null);

        if ($videoUrl) {
            $videoPath = "{$tempDir}/video.mp4";
            if ($this->streamToFile($videoUrl, $videoPath)) {
                return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
            }
        }

        return null;
    }

    private function tryTikWm(string $url, string $tempDir): ?DownloadResult
    {
        $ch = curl_init('https://www.tikwm.com/api/');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'url'    => $url,
                'count'  => 12,
                'cursor' => 0,
                'web'    => 1,
                'hd'     => 1,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Referer: https://www.tikwm.com/',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return null;
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data) || ($data['code'] ?? -1) !== 0 || empty($data['data'])) {
            return null;
        }

        $item = $data['data'];

        // Карусель фото
        if (!empty($item['images']) && is_array($item['images'])) {
            $images = [];
            foreach ($item['images'] as $idx => $imgUrl) {
                $target = sprintf('%s/slide_%02d.jpg', $tempDir, $idx + 1);
                if ($this->streamToFile($imgUrl, $target)) {
                    $images[] = $target;
                }
            }

            if (!empty($images)) {
                return new DownloadResult(MediaType::CAROUSEL, $images, $tempDir);
            }
        }

        // Видео без водяного знака
        $videoUrl = $item['play'] ?? ($item['wmplay'] ?? null);
        if ($videoUrl) {
            if (str_starts_with($videoUrl, '/')) {
                $videoUrl = 'https://www.tikwm.com' . $videoUrl;
            }

            $videoPath = "{$tempDir}/video.mp4";
            if ($this->streamToFile($videoUrl, $videoPath)) {
                return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
            }
        }

        return null;
    }

    /**
     * Потоковая запись файла напрямую на диск (0 байт лишней RAM)
     */
    private function streamToFile(string $url, string $destPath): bool
    {
        $fp = fopen($destPath, 'w+');
        if (!$fp) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        // Проверяем: успешный HTTP-код и размер больше 50 КБ (защита от скачивания 2 КБ HTML с ошибкой)
        if ($code === 200 && file_exists($destPath) && filesize($destPath) > 50000) {
            return true;
        }

        if (file_exists($destPath)) {
            @unlink($destPath);
        }

        return false;
    }
}
