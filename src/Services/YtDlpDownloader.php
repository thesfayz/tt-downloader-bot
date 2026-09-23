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

        // 1. Проверяем страницу через __UNIVERSAL_DATA_FOR_REHYDRATION__
        $mediaData = $this->extractDataFromHtml($url, $tempDir);

        if ($mediaData !== null) {
            return $mediaData;
        }

        // 2. Если через парсинг HTML не вышло — быстрый фоллбек через yt-dlp
        // -f "b[ext=mp4]/best" берет сразу цельный mp4 БЕЗ необходимости склеивать через ffmpeg
        $videoPath = "{$tempDir}/video.mp4";
        $videoCmd = sprintf(
            'yt-dlp --no-warnings --socket-timeout %d -f "b[ext=mp4]/best" --no-part -o %s %s 2>&1',
            $this->socketTimeout,
            escapeshellarg($videoPath),
            escapeshellarg($url)
        );
        exec($videoCmd);

        if (file_exists($videoPath) && filesize($videoPath) > 50000) {
            return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
        }

        (new DownloadResult(MediaType::UNKNOWN, [], $tempDir))->cleanup();
        return null;
    }

    private function extractDataFromHtml(string $url, string $tempDir): ?DownloadResult
    {
        $context = stream_context_create([
            'http' => [
                'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n",
                'timeout' => 15,
            ],
        ]);

        $html = @file_get_contents($url, false, $context);
        if (!$html) {
            return null;
        }

        if (!preg_match('/<script id="__UNIVERSAL_DATA_FOR_REHYDRATION__"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
            return null;
        }

        $data = json_decode(trim($matches[1]), true);
        if (!is_array($data)) {
            return null;
        }

        $itemStruct = $data['__DEFAULT_SCOPE__']['webapp.video-detail']['itemInfo']['itemStruct']
            ?? $this->findKeyRecursive($data, 'itemStruct');

        if (!is_array($itemStruct)) {
            return null;
        }

        // А) Проверяем, фото-карусель ли это
        $imagePostInfo = $itemStruct['imagePost'] ?? null;
        if (!empty($imagePostInfo['images'])) {
            $downloaded = [];
            foreach ($imagePostInfo['images'] as $idx => $img) {
                $urlList = $img['displayImage']['urlList'] ?? ($img['imageURL']['urlList'] ?? []);
                if (empty($urlList)) {
                    continue;
                }

                $imgUrl = $urlList[0];
                $target = sprintf('%s/slide_%02d.jpg', $tempDir, $idx + 1);

                $fileData = @file_get_contents($imgUrl, false, $context);
                if ($fileData !== false && strlen($fileData) > 5000) {
                    file_put_contents($target, $fileData);
                    $downloaded[] = $target;
                }
            }

            if (!empty($downloaded)) {
                return new DownloadResult(MediaType::CAROUSEL, $downloaded, $tempDir);
            }
        }

        // Б) Если это обычное видео — забираем прямую ссылку playAddr без водяного знака
        $playUrl = $itemStruct['video']['playAddr'] ?? ($itemStruct['video']['downloadAddr'] ?? null);
        if ($playUrl) {
            $videoPath = "{$tempDir}/video.mp4";
            if ($this->downloadFastStream($playUrl, $videoPath)) {
                return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
            }
        }

        return null;
    }

    private function downloadFastStream(string $videoUrl, string $destination): bool
    {
        $fp = fopen($destination, 'w+');
        if (!$fp) {
            return false;
        }

        $ch = curl_init($videoUrl);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://www.tiktok.com/',
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        return $success && $httpCode === 200 && filesize($destination) > 50000;
    }

    private function findKeyRecursive(array $array, string $keyToFind): mixed
    {
        if (array_key_exists($keyToFind, $array)) {
            return $array[$keyToFind];
        }

        foreach ($array as $value) {
            if (is_array($value)) {
                $result = $this->findKeyRecursive($value, $keyToFind);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }
}