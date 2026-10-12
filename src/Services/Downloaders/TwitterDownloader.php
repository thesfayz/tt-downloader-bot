<?php

declare(strict_types=1);

namespace App\Services\Downloaders;

use App\Contracts\PlatformDownloaderInterface;
use App\DTO\DownloadResult;
use App\DTO\MediaType;

final class TwitterDownloader implements PlatformDownloaderInterface
{
    public function __construct(
        private readonly int $socketTimeout = 30
    ) {}

    public function supports(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_ends_with($host, 'twitter.com')
            || str_ends_with($host, 'x.com')
            || str_contains($host, 'fxtwitter.com')
            || str_contains($host, 'vxtwitter.com')
            || str_contains($host, 'fixupx.com');
    }

    public function download(string $url): ?DownloadResult
    {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('tw_dl_', true);
        if (!@mkdir($tempDir, 0777, true)) {
            return null;
        }

        // 1. Проверяем, есть ли в твите фотографии через FxTwitter API
        $tweetId = $this->extractTweetId($url);
        if ($tweetId !== null) {
            $photoUrls = $this->fetchPhotoUrls($tweetId);
            if (!empty($photoUrls)) {
                $images = $this->downloadPhotos($photoUrls, $tempDir);
                if (!empty($images)) {
                    return new DownloadResult(MediaType::CAROUSEL, $images, $tempDir);
                }
            }
        }

        // 2. Если фоток нет — значит это видео: качаем через нативный yt-dlp
        $videoPath = "{$tempDir}/video.mp4";
        $cmd = sprintf(
            'yt-dlp --no-warnings --no-playlist --socket-timeout %d --max-filesize 45M '
            . '-S "vcodec:h264,res:720" -f "b/bv*+ba" --merge-output-format mp4 --no-part -o %s %s 2>&1',
            $this->socketTimeout,
            escapeshellarg($videoPath),
            escapeshellarg($url)
        );
        exec($cmd);

        if (file_exists($videoPath) && filesize($videoPath) > 50000) {
            return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
        }

        (new DownloadResult(MediaType::UNKNOWN, [], $tempDir))->cleanup();
        return null;
    }

    private function extractTweetId(string $url): ?string
    {
        if (preg_match('/status\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function fetchPhotoUrls(string $tweetId): array
    {
        // Пробуем fxtwitter, с фоллбэком на vxtwitter
        $endpoints = [
            "https://api.fxtwitter.com/status/{$tweetId}",
            "https://api.vxtwitter.com/status/{$tweetId}",
        ];

        $context = stream_context_create([
            'http' => [
                'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\nAccept: application/json\r\n",
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        foreach ($endpoints as $apiUrl) {
            $raw = @file_get_contents($apiUrl, false, $context);
            if (!$raw) {
                continue;
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }

            // Формат FxTwitter: tweet.media.photos
            $tweet = $data['tweet'] ?? $data['status'] ?? null;
            if (is_array($tweet) && !empty($tweet['media']['photos'])) {
                $urls = [];
                foreach ($tweet['media']['photos'] as $item) {
                    if (!empty($item['url']) && is_string($item['url'])) {
                        $urls[] = $item['url'];
                    }
                }
                if (!empty($urls)) {
                    return $urls;
                }
            }

            // Формат vxTwitter: media_extended или mediaURLs
            if (!empty($data['media_extended']) && is_array($data['media_extended'])) {
                $urls = [];
                foreach ($data['media_extended'] as $item) {
                    if (($item['type'] ?? '') === 'image' && !empty($item['url'])) {
                        $urls[] = $item['url'];
                    }
                }
                if (!empty($urls)) {
                    return $urls;
                }
            }

            if (!empty($data['mediaURLs']) && is_array($data['mediaURLs'])) {
                return array_values(array_filter($data['mediaURLs'], 'is_string'));
            }
        }

        return [];
    }

    /**
     * @param list<string> $photoUrls
     * @return list<string>
     */
    private function downloadPhotos(array $photoUrls, string $tempDir): array
    {
        $context = stream_context_create([
            'http' => [
                'header'  => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n",
                'timeout' => 12,
            ],
        ]);

        $downloaded = [];
        foreach ($photoUrls as $idx => $photoUrl) {
            $data = @file_get_contents($photoUrl, false, $context);
            if ($data !== false && strlen($data) > 2000) {
                $target = sprintf('%s/tweet_photo_%02d.jpg', $tempDir, $idx + 1);
                file_put_contents($target, $data);
                $downloaded[] = $target;
            }
        }

        return $downloaded;
    }
}
