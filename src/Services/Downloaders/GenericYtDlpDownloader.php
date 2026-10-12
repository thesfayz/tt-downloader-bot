<?php

declare(strict_types=1);

namespace App\Services\Downloaders;

use App\Contracts\PlatformDownloaderInterface;
use App\DTO\DownloadResult;
use App\DTO\MediaType;

final class GenericYtDlpDownloader implements PlatformDownloaderInterface
{
    public function __construct(
        private readonly int $socketTimeout = 30
    ) {}

    public function supports(string $url): bool
    {
        // Подходит для Twitter (X), Reddit, Pinterest, VK, YouTube и др.
        // Является фоллбэком, если передана валидная ссылка
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public function download(string $url): ?DownloadResult
    {
        $tempDir = sys_get_temp_dir() . '/' . uniqid('generic_dl_', true);
        if (!@mkdir($tempDir, 0777, true)) {
            return null;
        }

        $videoPath = "{$tempDir}/video.mp4";

        // Лимит 45M защищает от превышения лимитов Telegram (50M) и спасает диск Render
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
}
