<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\DownloadResult;

interface PlatformDownloaderInterface
{
    /**
     * Проверяет, умеет ли этот класс работать с переданным URL.
     */
    public function supports(string $url): bool;

    /**
     * Скачивает медиа и возвращает результат.
     */
    public function download(string $url): ?DownloadResult;
}
