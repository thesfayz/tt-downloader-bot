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
        // Используем папку из Dockerfile, если она есть, иначе системный tmp
        $this->downloadDir = $downloadDir ?: (is_dir('/app/downloads') ? '/app/downloads' : sys_get_temp_dir());
    }

    public function download(string $url): ?DownloadResult
    {
        $tempDir = $this->downloadDir . '/' . uniqid('tt_dl_', true);
        if (!@mkdir($tempDir, 0777, true)) {
            error_log("[DOWNLOADER] Не удалось создать директорию: $tempDir");
            return null;
        }

        error_log("[DOWNLOADER] Начинаем загрузку: $url в $tempDir");

        // 1. Пробуем внешние API (быстро, без нагрузки на CPU)
        $apiResult = $this->tryExternalApis($url, $tempDir);
        if ($apiResult !== null) {
            return $apiResult;
        }

        // 2. Фоллбэк на yt-dlp с форсом мобильного API и склейкой через ffmpeg
        $ytResult = $this->tryYtDlp($url, $tempDir);
        if ($ytResult !== null) {
            return $ytResult;
        }

        error_log("[DOWNLOADER] Все способы провалились");
        (new DownloadResult(MediaType::UNKNOWN, [], $tempDir))->cleanup();
        return null;
    }

    private function tryExternalApis(string $url, string $tempDir): ?DownloadResult
    {
        $apis = [
            'TikWM' => fn() => $this->tryTikWm($url, $tempDir),
            'SnapTik' => fn() => $this->trySnapTik($url, $tempDir),
        ];

        foreach ($apis as $name => $fn) {
            error_log("[API:$name] Пробуем...");
            $result = $fn();
            if ($result !== null) {
                error_log("[API:$name] УСПЕХ");
                return $result;
            }
        }
        return null;
    }

    private function tryYtDlp(string $url, string $tempDir): ?DownloadResult
    {
        // Проверяем наличие yt-dlp и ffmpeg
        exec('which yt-dlp 2>&1', $ytOut, $ytCode);
        exec('which ffmpeg 2>&1', $ffOut, $ffCode);

        if ($ytCode !== 0) {
            error_log("[YT-DLP] НЕ НАЙДЕН в системе");
            return null;
        }
        if ($ffCode !== 0) {
            error_log("[YT-DLP] ffmpeg НЕ НАЙДЕН (нужен для склейки видео)");
        }

        $videoPath = "{$tempDir}/video.mp4";

        // КРИТИЧЕСКИ ВАЖНЫЕ аргументы для обхода блокировок Render и корректной склейки
        $cmd = sprintf(
            'yt-dlp --no-warnings --no-playlist --socket-timeout %d ' .
            '--extractor-args "tiktok:api_hostname=api16-normal-c-useast1a.tiktokv.com" ' .
            '--extractor-args "tiktok:api_version=19.4.0" ' .
            '-f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best" ' .
            '--merge-output-format mp4 ' .
            '--ffmpeg-location /usr/bin/ffmpeg ' . // Явно указываем путь из Alpine
            '--no-part ' .
            '--user-agent "com.zhiliaoapp.musically/2023405030 (Linux; U; Android 11; en_US; Pixel 4)" ' .
            '-o %s %s 2>&1',
            $this->socketTimeout,
            escapeshellarg($videoPath),
            escapeshellarg($url)
        );
        
        error_log("[YT-DLP] Запуск команды...");
        
        $output = [];
        $resultCode = 0;
        exec($cmd, $output, $resultCode);

        $lastLines = implode(' | ', array_slice($output, -5));
        error_log("[YT-DLP] Код возврата: $resultCode. Вывод: $lastLines");

        if ($resultCode !== 0) {
            error_log("[YT-DLP] Ошибка выполнения yt-dlp");
            return null;
        }

        if (!file_exists($videoPath)) {
            error_log("[YT-DLP] Файл не был создан по пути: $videoPath");
            return null;
        }

        $size = filesize($videoPath);
        error_log("[YT-DLP] Файл создан, размер: $size байт");

        if ($size < 10000) {
            error_log("[YT-DLP] Файл слишком мал ($size байт), вероятно, это не видео");
            @unlink($videoPath);
            return null;
        }

        if (!$this->isValidVideo($videoPath)) {
            error_log("[YT-DLP] Файл не прошел валидацию сигнатуры MP4");
            @unlink($videoPath);
            return null;
        }

        return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
    }

    private function tryTikWm(string $url, string $tempDir): ?DownloadResult
    {
        $ch = curl_init('https://www.tikwm.com/api/');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['url' => $url, 'count' => 12, 'cursor' => 0, 'web' => 1, 'hd' => 1]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Referer: https://www.tikwm.com/',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) return null;

        $data = json_decode((string)$response, true);
        if (!is_array($data) || ($data['code'] ?? -1) !== 0 || empty($data['data'])) {
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

    private function trySnapTik(string $url, string $tempDir): ?DownloadResult
    {
        $ch = curl_init('https://snaptik.app/abc2');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['url' => $url]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) return null;

        if (preg_match('/href="(https:\/\/[^"]*tikwm[^"]*\.mp4[^"]*)"/i', $response, $m) ||
            preg_match('/href="(https:\/\/[^"]*snaptik[^"]*\.mp4[^"]*)"/i', $response, $m)) {
            $videoPath = "{$tempDir}/video.mp4";
            if ($this->streamToFile(html_entity_decode($m[1]), $videoPath)) {
                return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
            }
        }
        return null;
    }

    private function streamToFile(string $url, string $destPath): bool
    {
        $fp = fopen($destPath, 'w+');
        if (!$fp) return false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 45,
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

        // Проверяем сигнатуру MP4 (ftyp), но исключаем M4A (чистый аудио)
        if (strpos($header, 'ftyp') !== false) {
            if (strpos($header, 'M4A') !== false || strpos($header, 'M4B') !== false) {
                error_log("[VALIDATE] Это аудио-контейнер M4A, а не видео!");
                return false;
            }
            return true;
        }

        if (strpos($header, 'webm') !== false || strpos($header, 'matroska') !== false) {
            return true;
        }

        error_log("[VALIDATE] Неверная сигнатура файла: " . bin2hex(substr($header, 0, 16)));
        return false;
    }
}
