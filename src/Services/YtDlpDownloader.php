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

        // 1. Сначала проверяем: вдруг это карусель фоток (через стейт страницы)
        $images = $this->extractImagesFromHtml($url, $tempDir);
        if (!empty($images)) {
            return new DownloadResult(MediaType::CAROUSEL, $images, $tempDir);
        }

        // 2. Если это не карусель — качаем видео через стабильный yt-dlp
        $videoPath = "{$tempDir}/video.mp4";
        $videoCmd = sprintf(
            'yt-dlp --no-warnings --socket-timeout %d -f "bv*[vcodec!=none]+ba/b[vcodec!=none]/b" --merge-output-format mp4 --no-part -o %s %s 2>&1',
            $this->socketTimeout,
            escapeshellarg($videoPath),
            escapeshellarg($url)
        );
        exec($videoCmd);

        if (file_exists($videoPath) && filesize($videoPath) > 50000) {
            return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
        }

        // Если и это не видео, пробуем достать реальный URL через yt-dlp и еще раз проверить на фото
        $jsonCmd = sprintf(
            'yt-dlp -J --no-warnings --socket-timeout %d %s 2>&1',
            $this->socketTimeout,
            escapeshellarg($url)
        );
        $rawJson = shell_exec($jsonCmd);
        $meta = json_decode((string)$rawJson, true);

        $webpageUrl = $meta['webpage_url'] ?? null;
        if ($webpageUrl && $webpageUrl !== $url) {
            $images = $this->extractImagesFromHtml($webpageUrl, $tempDir);
            if (!empty($images)) {
                return new DownloadResult(MediaType::CAROUSEL, $images, $tempDir);
            }
        }

        (new DownloadResult(MediaType::UNKNOWN, [], $tempDir))->cleanup();
        return null;
    }

    /**
     * @return list<string>
     */
    private function extractImagesFromHtml(string $url, string $tempDir): array
    {
        $context = stream_context_create([
            'http' => [
                'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n",
                'timeout' => 15,
            ],
        ]);

        $html = @file_get_contents($url, false, $context);
        if (!$html) {
            return [];
        }

        if (!preg_match('/<script id="__UNIVERSAL_DATA_FOR_REHYDRATION__"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
            return [];
        }

        $data = json_decode(trim($matches[1]), true);
        if (!is_array($data)) {
            return [];
        }

        $itemStruct = $data['__DEFAULT_SCOPE__']['webapp.video-detail']['itemInfo']['itemStruct']
            ?? $this->findKeyRecursive($data, 'itemStruct');

        if (!is_array($itemStruct)) {
            return [];
        }

        $imagePostInfo = $itemStruct['imagePost'] ?? null;
        if (empty($imagePostInfo['images'])) {
            return [];
        }

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

        return $downloaded;
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