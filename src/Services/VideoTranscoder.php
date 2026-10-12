<?php

declare(strict_types=1);

namespace App\Services;

final class VideoTranscoder
{
    public function __construct(
        private readonly int $timeoutSeconds = 60
    ) {}

    public function ensureH264(string $path): string
    {
        // Если кодек уже не HEVC — процессор не трогаем вообще
        if ($this->probeCodec($path) !== 'hevc') {
            return $path;
        }

        $out = preg_replace('/\.mp4$/', '_h264.mp4', $path);

        // preset ultrafast бережёт CPU на Render и укладывается в тайминги вебхука
        $cmd = sprintf(
            'timeout %d ffmpeg -y -i %s -c:v libx264 -preset ultrafast -crf 23 '
            . '-pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
            $this->timeoutSeconds,
            escapeshellarg($path),
            escapeshellarg($out)
        );
        exec($cmd, $output, $code);

        if ($code === 0 && file_exists($out) && filesize($out) > 50000) {
            @unlink($path);
            return $out;
        }

        @unlink($out);
        return $path; // Фолбэк: если ffmpeg упал или не уложился по времени — отдаём оригинал
    }

    private function probeCodec(string $path): ?string
    {
        $cmd = sprintf(
            'ffprobe -v error -select_streams v:0 -show_entries stream=codec_name '
            . '-of default=noprint_wrappers=1:nokey=1 %s 2>&1',
            escapeshellarg($path)
        );
        $codec = trim((string) shell_exec($cmd));

        return $codec !== '' ? $codec : null;
    }
}
