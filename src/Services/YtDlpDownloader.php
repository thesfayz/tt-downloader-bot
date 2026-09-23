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

        // 1. Проверяем метаданные через JSON
        $jsonCmd = sprintf(
            'yt-dlp -J --no-warnings --socket-timeout %d %s 2>&1',
            $this->socketTimeout,
            escapeshellarg($url)
        );
        $rawJson = shell_exec($jsonCmd);
        $meta = json_decode((string)$rawJson, true);

        $hasVideo = !empty($meta['vcodec']) && $meta['vcodec'] !== 'none';
        $isPhoto = str_contains($url, '/photo/') || !$hasVideo;

        // 2. Обработка карусели слайдов
        if ($isPhoto) {
            $thumbnails = $meta['thumbnails'] ?? [];
            $images = [];
            $seenUrls = [];

            // TikTok отдает список всех картинок карусели прямо в метаданных
            foreach ($thumbnails as $idx => $thumb) {
                $rawImgUrl = $thumb['url'] ?? null;
                if (!$rawImgUrl) {
                    continue;
                }

                // Убираем параметры сжатия из URL, чтобы не дублировать размеры
                $cleanUrl = strtok($rawImgUrl, '?');
                if (isset($seenUrls[$cleanUrl])) {
                    continue;
                }
                $seenUrls[$cleanUrl] = true;

                $targetFile = sprintf('%s/slide_%02d.jpg', $tempDir, count($images) + 1);

                // Быстро скачиваем фото
                $content = @file_get_contents($rawImgUrl);
                if ($content !== false && strlen($content) > 10000) {
                    file_put_contents($targetFile, $content);
                    $images[] = $targetFile;
                }
            }

            // Если через метаданные не скачалось, используем фоллбек через yt-dlp
            if (empty($images)) {
                $cmd = sprintf(
                    'yt-dlp --no-warnings --socket-timeout %d --write-all-thumbnails --skip-download -P %s %s 2>&1',
                    $this->socketTimeout,
                    escapeshellarg($tempDir),
                    escapeshellarg($url)
                );
                exec($cmd);

                $files = scandir($tempDir) ?: [];
                $seenHashes = [];
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..') continue;
                    $path = "{$tempDir}/{$file}";
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && filesize($path) > 10000) {
                        $hash = md5_file($path);
                        if ($hash !== false && !isset($seenHashes[$hash])) {
                            $seenHashes[$hash] = true;
                            $images[] = $path;
                        } else {
                            @unlink($path);
                        }
                    }
                }
            }

            if (!empty($images)) {
                return new DownloadResult(MediaType::CAROUSEL, $images, $tempDir);
            }
        }

        // 3. Обработка видеопотока
        $videoPath = "{$tempDir}/video.mp4";
        $videoCmd = sprintf(
            'yt-dlp --no-warnings --socket-timeout %d -f "bv*[vcodec!=none]+ba/b[vcodec!=none]" --merge-output-format mp4 -o %s %s 2>&1',
            $this->socketTimeout,
            escapeshellarg($videoPath),
            escapeshellarg($url)
        );
        exec($videoCmd);

        if (file_exists($videoPath) && filesize($videoPath) > 50000) {
            return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
        }

        // Если загрузка провалилась — очищаем директорию
        (new DownloadResult(MediaType::UNKNOWN, [], $tempDir))->cleanup();
        return null;
    }
}