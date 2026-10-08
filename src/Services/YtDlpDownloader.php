<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MediaDownloaderInterface;
use App\DTO\DownloadResult;
use App\DTO\MediaType;

final class YtDlpDownloader implements MediaDownloaderInterface
{
    private readonly string $downloadDir;

    public function __construct(
        private readonly int $socketTimeout = 30,
        ?string $downloadDir = null
    ) {
        $this->downloadDir = $downloadDir ?: (is_dir('/app/downloads') ? '/app/downloads' : sys_get_temp_dir());
    }

    public function download(string $url): ?DownloadResult
    {
        $tempDir = $this->downloadDir . '/' . uniqid('tt_dl_', true);
        if (!@mkdir($tempDir, 0777, true)) {
            error_log("[DOWNLOADER] Не удалось создать директорию: $tempDir");
            return null;
        }

        error_log("[DOWNLOADER] Начинаем загрузку: $url");

        // 1. Cobalt API (работает стабильно, не блокирует IP)
        $cobaltResult = $this->tryCobalt($url, $tempDir);
        if ($cobaltResult !== null) {
            error_log("[DOWNLOADER] Успех через Cobalt");
            return $cobaltResult;
        }

        // 2. TikWM (на случай если разбанят)
        $tikwmResult = $this->tryTikWm($url, $tempDir);
        if ($tikwmResult !== null) {
            error_log("[DOWNLOADER] Успех через TikWM");
            return $tikwmResult;
        }

        // 3. yt-dlp (последний шанс)
        $ytResult = $this->tryYtDlp($url, $tempDir);
        if ($ytResult !== null) {
            error_log("[DOWNLOADER] Успех через yt-dlp");
            return $ytResult;
        }

        error_log("[DOWNLOADER] Все способы провалились");
        (new DownloadResult(MediaType::UNKNOWN, [], $tempDir))->cleanup();
        return null;
    }

    private function tryCobalt(string $url, string $tempDir): ?DownloadResult
    {
        error_log("[Cobalt] Пробуем загрузку...");

        $ch = curl_init('https://api.cobalt.tools/');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'url' => $url,
                'downloadMode' => 'auto',
                'videoQuality' => '720',
                'filenameStyle' => 'basic',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("[Cobalt] HTTP ошибка: $httpCode, ответ: " . substr((string)$response, 0, 300));
            return null;
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            error_log("[Cobalt] Не JSON ответ");
            return null;
        }

        // Проверяем статус
        $status = $data['status'] ?? 'error';
        if ($status !== 'stream' && $status !== 'redirect' && $status !== 'tunnel') {
            error_log("[Cobalt] Статус: $status, сообщение: " . ($data['text'] ?? 'unknown'));
            return null;
        }

        // Получаем ссылку на видео
        $videoUrl = $data['url'] ?? null;
        if (!$videoUrl) {
            error_log("[Cobalt] Не найдена ссылка на видео");
            return null;
        }

        error_log("[Cobalt] Получена ссылка: " . substr($videoUrl, 0, 80));

        // Скачиваем видео
        $videoPath = "{$tempDir}/video.mp4";
        if ($this->streamToFile($videoUrl, $videoPath)) {
            return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
        }

        return null;
    }

    private function tryTikWm(string $url, string $tempDir): ?DownloadResult
    {
        error_log("[TikWM] Пробуем...");

        $ch = curl_init('https://www.tikwm.com/api/');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['url' => $url, 'count' => 12, 'cursor' => 0, 'web' => 1, 'hd' => 1]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Referer: https://www.tikwm.com/',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("[TikWM] HTTP $httpCode");
            return null;
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data) || ($data['code'] ?? -1) !== 0 || empty($data['data'])) {
            error_log("[TikWM] API error");
            return null;
        }

        $item = $data['data'];
        $videoUrl = $item['play'] ?? ($item['wmplay'] ?? null);
        if (!$videoUrl) return null;

        if (str_starts_with($videoUrl, '/')) {
            $videoUrl = 'https://www.tikwm.com' . $videoUrl;
        }

        $videoPath = "{$tempDir}/video.mp4";
        if ($this->streamToFile($videoUrl, $videoPath)) {
            return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
        }

        return null;
    }

    private function tryYtDlp(string $url, string $tempDir): ?DownloadResult
    {
        error_log("[YT-DLP] Пробуем...");

        exec('which yt-dlp 2>&1', $ytOut, $ytCode);
        if ($ytCode !== 0) {
            error_log("[YT-DLP] НЕ НАЙДЕН");
            return null;
        }

        $videoPath = "{$tempDir}/video.mp4";

        $cmd = sprintf(
            'yt-dlp --no-warnings --no-playlist --socket-timeout %d ' .
            '--extractor-args "tiktok:api_hostname=api16-normal-c-useast1a.tiktokv.com" ' .
            '--extractor-args "tiktok:api_version=19.4.0" ' .
            '-f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best" ' .
            '--merge-output-format mp4 ' .
            '--no-part ' .
            '--user-agent "com.zhiliaoapp.musically/2023405030 (Linux; U; Android 11; en_US; Pixel 4)" ' .
            '-o %s %s 2>&1',
            $this->socketTimeout,
            escapeshellarg($videoPath),
            escapeshellarg($url)
        );

        $output = [];
        $resultCode = 0;
        exec($cmd, $output, $resultCode);

        $lastLines = implode(' | ', array_slice($output, -5));
        error_log("[YT-DLP] Код: $resultCode. Вывод: $lastLines");

        if ($resultCode !== 0) {
            return null;
        }

        if (!file_exists($videoPath) || filesize($videoPath) < 10000) {
            @unlink($videoPath);
            return null;
        }

        if (!$this->isValidVideo($videoPath)) {
            @unlink($videoPath);
            return null;
        }

        return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
    }

    private function streamToFile(string $url, string $destPath): bool
    {
        $fp = fopen($destPath, 'w+');
        if (!$fp) return false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($fp);

        if ($code !== 200 || !file_exists($destPath) || filesize($destPath) < 10000) {
            @unlink($destPath);
            return false;
        }

        if (stripos((string)$contentType, 'text/html') !== false) {
            @unlink($destPath);
            return false;
        }

        return true;
    }

    private function isValidVideo(string $filePath): bool
    {
        $fp = fopen($filePath, 'rb');
        if (!$fp) return false;

        $header = fread($fp, 32);
        fclose($fp);

        if (strpos($header, 'ftyp') !== false) {
            if (strpos($header, 'M4A') !== false || strpos($header, 'M4B') !== false) {
                return false;
            }
            return true;
        }

        if (strpos($header, 'webm') !== false || strpos($header, 'matroska') !== false) {
            return true;
        }

        return false;
    }
}
