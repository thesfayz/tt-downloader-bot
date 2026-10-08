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
            error_log("[YT-DLP] Не удалось создать временную директорию: $tempDir");
            return null;
        }

        error_log("[YT-DLP] Начинаем загрузку: $url");

        // Пробуем API в порядке приоритета
        $apiResult = $this->tryExternalApis($url, $tempDir);
        if ($apiResult !== null) {
            error_log("[YT-DLP] Успешно загружено через API");
            return $apiResult;
        }

        // Фоллбэк на yt-dlp с проверкой
        $ytDlpResult = $this->tryYtDlp($url, $tempDir);
        if ($ytDlpResult !== null) {
            error_log("[YT-DLP] Успешно загружено через yt-dlp");
            return $ytDlpResult;
        }

        error_log("[YT-DLP] Все способы провалились");
        (new DownloadResult(MediaType::UNKNOWN, [], $tempDir))->cleanup();
        return null;
    }

    private function tryExternalApis(string $url, string $tempDir): ?DownloadResult
    {
        // 1. Try SSSTikTok (работает стабильно)
        $result = $this->trySSSTikTok($url, $tempDir);
        if ($result !== null) return $result;

        // 2. Try SnapTik
        $result = $this->trySnapTik($url, $tempDir);
        if ($result !== null) return $result;

        // 3. Try old TikWM
        $result = $this->tryTikWm($url, $tempDir);
        if ($result !== null) return $result;

        return null;
    }

    private function trySSSTikTok(string $url, string $tempDir): ?DownloadResult
    {
        error_log("[SSSTikTok] Пробуем загрузку...");
        
        $ch = curl_init('https://ssstik.io/abc');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'id' => $url,
                'locale' => 'en',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
                'Origin: https://ssstik.io',
                'Referer: https://ssstik.io/',
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("[SSSTikTok] HTTP ошибка: $httpCode");
            return null;
        }

        // Ищем ссылку на видео в HTML ответе
        if (preg_match('/href="(https:\/\/[^"]+\.mp4[^"]*)"/i', $response, $matches)) {
            $videoUrl = html_entity_decode($matches[1]);
            error_log("[SSSTikTok] Найдено видео: " . substr($videoUrl, 0, 50));
            
            $videoPath = "{$tempDir}/video.mp4";
            if ($this->streamToFile($videoUrl, $videoPath)) {
                return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
            }
        }

        error_log("[SSSTikTok] Не найдено видео в ответе");
        return null;
    }

    private function trySnapTik(string $url, string $tempDir): ?DownloadResult
    {
        error_log("[SnapTik] Пробуем загрузку...");
        
        // Сначала получаем токен
        $ch = curl_init('https://tiktokdownloader.com/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Делаем запрос на скачивание
        $ch = curl_init('https://tiktokdownloader.com/api');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'url' => $url,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("[SnapTik] HTTP ошибка: $httpCode");
            return null;
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            error_log("[SnapTik] Не JSON ответ: " . substr($response, 0, 100));
            return null;
        }

        // Проверяем разные форматы ответа
        $videoUrl = $data['video'] 
            ?? $data['result']['video'] 
            ?? ($data['data']['video'] ?? null);

        if ($videoUrl && is_string($videoUrl)) {
            error_log("[SnapTik] Найдено видео");
            $videoPath = "{$tempDir}/video.mp4";
            if ($this->streamToFile($videoUrl, $videoPath)) {
                return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
            }
        }

        error_log("[SnapTik] Не найдено видео в ответе");
        return null;
    }

    private function tryTikWm(string $url, string $tempDir): ?DownloadResult
    {
        error_log("[TikWM] Пробуем загрузку...");
        
        $ch = curl_init('https://www.tikwm.com/api/');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'url'    => $url,
                'count'  => 12,
                'cursor' => 0,
                'web'    => 1,
                'hd'     => 1,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
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
            error_log("[TikWM] HTTP ошибка: $httpCode");
            return null;
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            error_log("[TikWM] Не JSON: " . substr($response, 0, 150));
            return null;
        }

        if (($data['code'] ?? -1) !== 0 || empty($data['data'])) {
            error_log("[TikWM] API ошибка. Код: " . ($data['code'] ?? 'unknown'));
            return null;
        }

        $item = $data['data'];
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

    private function tryYtDlp(string $url, string $tempDir): ?DownloadResult
    {
        error_log("[YT-DLP] Проверяем наличие yt-dlp...");
        
        // Проверяем установлен ли yt-dlp
        exec('which yt-dlp 2>&1', $whichOutput, $whichCode);
        if ($whichCode !== 0) {
            error_log("[YT-DLP] yt-dlp не найден в системе");
            return null;
        }

        $videoPath = "{$tempDir}/video.mp4";
        $cmd = sprintf(
            'yt-dlp --no-warnings --no-playlist --socket-timeout %d -f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best" --merge-output-format mp4 --no-part -o %s %s 2>&1',
            $this->socketTimeout,
            escapeshellarg($videoPath),
            escapeshellarg($url)
        );
        
        error_log("[YT-DLP] Выполняем: " . substr($cmd, 0, 100));
        
        $output = [];
        $resultCode = 0;
        exec($cmd, $output, $resultCode);

        error_log("[YT-DLP] Результат: код=$resultCode, вывод=" . implode(' ', array_slice($output, -3)));

        if ($resultCode === 0 && file_exists($videoPath) && filesize($videoPath) > 100000) {
            if ($this->isValidVideo($videoPath)) {
                return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
            }
        }

        return null;
    }

    private function streamToFile(string $url, string $destPath): bool
    {
        $fp = fopen($destPath, 'w+');
        if (!$fp) {
            error_log("[STREAM] Не удалось открыть файл: $destPath");
            return false;
        }

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
        $totalSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        fclose($fp);

        error_log("[STREAM] HTTP: $code, Content-Type: $contentType, Size: $totalSize");

        if ($code !== 200) {
            @unlink($destPath);
            return false;
        }

        if (!file_exists($destPath) || filesize($destPath) < 50000) {
            error_log("[STREAM] Файл слишком мал или не существует");
            @unlink($destPath);
            return false;
        }

        if (stripos((string)$contentType, 'text/html') !== false) {
            error_log("[STREAM] Скачан HTML вместо медиа");
            @unlink($destPath);
            return false;
        }

        return true;
    }

    private function isValidVideo(string $filePath): bool
    {
        $fp = fopen($filePath, 'rb');
        if (!$fp) return false;
        
        $header = fread($fp, 12);
        fclose($fp);

        if (strpos($header, 'ftyp') !== false || strpos($header, 'webm') !== false) {
            return true;
        }

        error_log("[VALIDATE] Неверная сигнатура видео");
        return false;
    }
}
