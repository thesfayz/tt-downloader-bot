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

        // 1. Пробуем через публичный инстанс Cobalt API
        $cobaltResult = $this->downloadViaCobalt($url, $tempDir);
        if ($cobaltResult !== null) {
            return $cobaltResult;
        }

        // 2. Фоллбэк: если Cobalt не ответил, используем yt-dlp напрямую
        $videoPath = "{$tempDir}/video.mp4";
        $cmd = sprintf(
            'yt-dlp --no-warnings --socket-timeout 20 -f "b[ext=mp4]/best" --no-part -o %s %s 2>&1',
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

    private function downloadViaCobalt(string $url, string $tempDir): ?DownloadResult
    {
        // Публичные рабочие инстансы Cobalt
        $instances = [
            'https://cobalt-api.kwiatekm.pl',
            'https://api.cobalt.tools',
        ];

        foreach ($instances as $instance) {
            $ch = curl_init("{$instance}/");
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'url'           => $url,
                    'videoQuality'  => '720',
                    'youtubeVideoCodec' => 'h264',
                ], JSON_THROW_ON_ERROR),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                continue;
            }

            $data = json_decode((string)$response, true);
            if (!is_array($data)) {
                continue;
            }

            // Обработка карусели картинок
            if (($data['status'] ?? '') === 'picker' && !empty($data['picker'])) {
                $images = [];
                foreach ($data['picker'] as $idx => $item) {
                    if (($item['type'] ?? '') !== 'photo' && empty($item['url'])) {
                        continue;
                    }

                    $target = sprintf('%s/slide_%02d.jpg', $tempDir, $idx + 1);
                    if ($this->saveStreamToFile($item['url'], $target)) {
                        $images[] = $target;
                    }
                }

                if (!empty($images)) {
                    return new DownloadResult(MediaType::CAROUSEL, $images, $tempDir);
                }
            }

            // Обработка видео (status === 'tunnel' или 'redirect')
            $downloadUrl = $data['url'] ?? null;
            if ($downloadUrl) {
                $videoPath = "{$tempDir}/video.mp4";
                if ($this->saveStreamToFile($downloadUrl, $videoPath)) {
                    // Валидация: файл должен быть больше 100 КБ, чтобы отсечь битые заглушки
                    if (filesize($videoPath) > 100000) {
                        return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
                    }
                }
            }
        }

        return null;
    }

    private function saveStreamToFile(string $sourceUrl, string $destinationPath): bool
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
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($code === 200 && file_exists($destinationPath) && filesize($destinationPath) > 50000) {
            return true;
        }

        if (file_exists($destinationPath)) {
            @unlink($destinationPath);
        }

        return false;
    }
}
