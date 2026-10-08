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

        // Шаг 1: Внешние API
        $apiResult = $this->tryExternalApis($url, $tempDir);
        if ($apiResult !== null) {
            error_log("[YT-DLP] Успешно загружено через внешнее API");
            return $apiResult;
        }

        // Шаг 2: Фоллбэк на yt-dlp
        $videoPath = "{$tempDir}/video.mp4";
        // Добавили --no-check-certificate и более строгий выбор формата MP4 + логирование вывода
        $cmd = sprintf(
            'yt-dlp --no-warnings --no-playlist --socket-timeout %d -f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/best[ext=mp4]/best" --merge-output-format mp4 --no-part -o %s %s 2>&1',
            $this->socketTimeout,
            escapeshellarg($videoPath),
            escapeshellarg($url)
        );
        
        error_log("[YT-DLP] Выполняем команду: " . str_replace($url, '[URL]', $cmd));
        
        $output = [];
        $resultCode = 0;
        exec($cmd, $output, $resultCode);

        if ($resultCode !== 0) {
            error_log("[YT-DLP] Ошибка yt-dlp (код $resultCode): " . implode("\n", $output));
        }

        if (file_exists($videoPath) && filesize($videoPath) > 100000 && $this->isValidVideo($videoPath)) {
            error_log("[YT-DLP] Успешно загружено через yt-dlp");
            return new DownloadResult(MediaType::VIDEO, [$videoPath], $tempDir);
        }

        error_log("[YT-DLP] Все способы загрузки провалились. Очистка.");
        (new DownloadResult(MediaType::UNKNOWN, [], $tempDir))->cleanup();
        return null;
    }

    private function tryExternalApis(string $url, string $tempDir): ?DownloadResult
    {
        $result = $this->tryTiklydown($url, $tempDir);
        if ($result !== null) return $result;

        $result = $this->tryTikWm($url, $tempDir);
        if ($result !== null) return $result;

        return null;
    }

    private function tryTiklydown(string $url, string $tempDir): ?DownloadResult
    {
        $apiUrl = 'https://api.tiklydown.eu.org/api/download?url=' . urlencode($url);
        $response = $this->makeCurlRequest($apiUrl, [
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
        ]);

        if (!$response) return null;

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            error_log("[Tiklydown] Неверный JSON ответ. Возможно, блокировка. Первые 100 символов: " . substr($response, 0, 100));
            return null;
        }

        if (!empty($data['images']) && is_array($data['images'])) {
            $images = [];
            foreach ($data['images'] as $idx => $img) {
                $imgUrl = is_array($img) ? ($img['url'] ?? null) : $img;
                if (!$imgUrl) continue;

                $target = sprintf('%s/slide_%02d.jpg', $tempDir, $idx + 1);
                if ($this->streamToFile($imgUrl, $target)) {
                    $images[] = $target;
                }
            }
            if (!empty($images)) return new DownloadResult(MediaType::CAROUSEL, $images, $tempDir);
        }

        $videoUrl = $data['video']['noWatermark'] ?? $data['video']['watermark'] ?? ($data['video']['url'] ?? null);
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
        $response = $this->makeCurlRequest('https://www.tikwm.com/api/', [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'url' => $url, 'count' => 12, 'cursor' => 0, 'web' => 1, 'hd' => 1,
            ]),
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Referer: https://www.tikwm.com/',
            ],
        ]);

        if (!$response) return null;

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            error_log("[TikWM] Неверный JSON ответ (возможно, Cloudflare block). Начало ответа: " . substr($response, 0, 150));
            return null;
        }

        if (($data['code'] ?? -1) !== 0 || empty($data['data'])) {
            error_log("[TikWM] API вернуло ошибку. Код: " . ($data['code'] ?? 'unknown') . ", Сообщение: " . ($data['msg'] ?? 'none'));
            return null;
        }

        $item = $data['data'];

        if (!empty($item['images']) && is_array($item['images'])) {
            $images = [];
            foreach ($item['images'] as $idx => $imgUrl) {
                $target = sprintf('%s/slide_%02d.jpg', $tempDir, $idx + 1);
                if ($this->streamToFile($imgUrl, $target)) {
                    $images[] = $target;
                }
            }
            if (!empty($images)) return new DownloadResult(MediaType::CAROUSEL, $images, $tempDir);
        }

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
     * Универсальный CURL-запрос для упрощения кода
     */
    private function makeCurlRequest(string $url, array $customOptions = []): ?string
    {
        $ch = curl_init($url);
        $defaultOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false, // Важно для бесплатных хостингов с устаревшими сертификатами
        ];
        curl_setopt_array($ch, $defaultOptions + $customOptions);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            error_log("[CURL] Ошибка запроса к $url. HTTP: $httpCode. CURL Error: $curlError");
            return null;
        }

        return $response;
    }

    /**
     * Потоковая запись с проверкой, что это реально видео/картинка, а не HTML-страница ошибки
     */
    private function streamToFile(string $url, string $destPath): bool
    {
        $fp = fopen($destPath, 'w+');
        if (!$fp) return false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        ]);
        
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        fclose($fp);

        // 1. Проверяем HTTP код
        if ($code !== 200) {
            @unlink($destPath);
            return false;
        }

        // 2. Проверяем размер (защита от пустых файлов)
        if (!file_exists($destPath) || filesize($destPath) < 50000) {
            @unlink($destPath);
            return false;
        }

        // 3. КРИТИЧЕСКИ ВАЖНО: Проверяем, не скачали ли мы HTML вместо видео
        if (stripos((string)$contentType, 'text/html') !== false) {
            error_log("[STREAM] Попытка сохранить HTML как медиафайл: $url");
            @unlink($destPath);
            return false;
        }

        return true;
    }

    /**
     * Проверка "магических байтов" файла, чтобы убедиться, что это видео
     */
    private function isValidVideo(string $filePath): bool
    {
        $fp = fopen($filePath, 'rb');
        if (!$fp) return false;
        
        $header = fread($fp, 12);
        fclose($fp);

        // Проверяем сигнатуру MP4 (ftyp) или WebM
        if (strpos($header, 'ftyp') !== false || strpos($header, 'webm') !== false || strpos($header, 'matroska') !== false) {
            return true;
        }

        error_log("[VALIDATE] Файл $filePath не является валидным видео (неверная сигнатура).");
        return false;
    }
}
