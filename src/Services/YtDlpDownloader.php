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

        // ШАГ 1: Пробуем быстрый парсер через API без водяного знака (0.5 сек)
        $fastResult = $this->tryFastApiDownload($url, $tempDir);
        if ($fastResult !== null) {
            return $fastResult;
        }

        // ШАГ 2: Фоллбэк на карусель из HTML (если это слайды)
        $images = $this->extractImagesFromHtml($url, $tempDir);
        if (!empty($images)) {
            return new DownloadResult(MediaType::CAROUSEL, $images, $tempDir);
        }

        // ШАГ 3: Фоллбэк на yt-dlp с форсированным сжатием в H264, если прилетел HEVC
        $videoPath = "{$tempDir}/video.mp4";
        
        // Качаем avc1, если нет - берем лучшее
        $videoCmd = sprintf(
            'yt-dlp --no-warnings --socket-timeout %d -f "bv*[vcodec^=avc1]+ba/b[vcodec^=avc1]/best" --merge-output-format mp4 --no-part -o %s %s 2>&1',
            $this->socketTimeout,
            escapeshellarg($videoPath),
            escapeshellarg($url)
        );
        exec($videoCmd);

        if (file_exists($videoPath) && filesize($videoPath) > 50000) {
            // Проверяем: если видео всё-таки скачалось в HEVC, быстро ремуксим его через ffmpeg
            $fixedVideoPath = $this->ensureH264Compatible($videoPath, $tempDir);
            return new DownloadResult(MediaType::VIDEO, [$fixedVideoPath], $tempDir);
        }

        (new DownloadResult(MediaType::UNKNOWN, [], $tempDir))->cleanup();
        return null;
    }

    /**
     * Быстрый парсер без водяных знаков через открытый TikWM mirror / API
     */
    private function tryFastApiDownload(string $url, string $tempDir): ?DownloadResult
    {
        $apiUrl = 'https://api.tiklydown.eu.org/api/download?url=' . urlencode($url);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
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

        // Если это карусель фоток
        if (!empty($data['images']) && is_array($data['images'])) {
            $downloaded = [];
            foreach ($data['images'] as $idx => $img) {
                $imgUrl = is_array($img) ? ($img['url'] ?? null) : $img;
                if (!$imgUrl) continue;

                $target = sprintf('%s/slide_%02d.jpg', $tempDir, $idx + 1);
                $content = @file_get_contents($imgUrl);
                if ($content && strlen($content) > 3000) {
                    file_put_contents($target, $content);
                    $downloaded[] = $target;
                }
            }
            if (!empty($downloaded)) {
                return new DownloadResult(MediaType::CAROUSEL, $downloaded, $tempDir);
            }
        }

        // Если это видео без водяного знака
        $videoUrl = $data['video']['noWatermark'] 
            ?? $data['video']['watermark'] 
            ?? ($data['video']['url'] ?? null);

        if ($videoUrl) {
            $videoPath = "{$tempDir}/video.mp4";
            $fp = fopen($videoPath, 'w+');
            
            $ch = curl_init($videoUrl);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'com.zhiliaoapp.musically/2022600030 (Linux; U; Android 12; en_US; Pixel 6)',
            ]);
            curl_exec($ch);
            curl_close($ch);
            fclose($fp);

            if (file_exists($videoPath) && filesize($videoPath) > 50000) {
                return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
            }
        }

        return null;
    }

    /**
     * Если yt-dlp всё-таки отдал HEVC (hvc1), ffmpeg быстро перегоняет его в h264
     */
    private function ensureH264Compatible(string $videoPath, string $tempDir): string
    {
        // Проверяем кодек через ffprobe
        $codec = trim((string)shell_exec(sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 %s',
            escapeshellarg($videoPath)
        )));

        // Если это уже h264 — отдаем как есть
        if ($codec === 'h264') {
            return $videoPath;
        }

        // Если это hevc/h265 — пережимаем с ultrafast пресетом (займет 3-4 секунды)
        $outPath = "{$tempDir}/fixed_video.mp4";
        $cmd = sprintf(
            'ffmpeg -y -i %s -c:v libx264 -preset ultrafast -crf 26 -c:a copy %s 2>&1',
            escapeshellarg($videoPath),
            escapeshellarg($outPath)
        );
        exec($cmd);

        return (file_exists($outPath) && filesize($outPath) > 50000) ? $outPath : $videoPath;
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
