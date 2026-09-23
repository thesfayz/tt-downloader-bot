<?php

declare(strict_type=1);

namespace App\Contracts;

use App\DTO\DownloadResult;

interface MediaDownloaderInterface
{
    public function download(string $url): ?DownloadResult;
}