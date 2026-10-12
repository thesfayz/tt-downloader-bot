<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\MediaDownloaderInterface;
use App\Contracts\PlatformDownloaderInterface;
use App\DTO\DownloadResult;

final class MediaDownloaderManager implements MediaDownloaderInterface
{
    /**
     * @var list<PlatformDownloaderInterface>
     */
    private array $downloaders;

    /**
     * @param iterable<PlatformDownloaderInterface> $downloaders
     */
    public function __construct(iterable $downloaders)
    {
        $this->downloaders = is_array($downloaders) ? $downloaders : iterator_to_array($downloaders);
    }

    public function download(string $url): ?DownloadResult
    {
        foreach ($this->downloaders as $downloader) {
            if ($downloader->supports($url)) {
                $result = $downloader->download($url);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }
}
