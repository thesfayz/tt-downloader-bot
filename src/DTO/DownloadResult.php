<?php

declare(strict_types=1);

namespace App\DTO;

final class DownloadResult
{
    /**
     * @param string[] $filePaths
     */
    public function __construct(
        public readonly MediaType $type,
        public readonly array $filePaths,
        public readonly string $tempDirectory
    ) {}

    public function cleanup(): void
    {
        foreach ($this->filePaths as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        if (is_dir($this->tempDirectory)) {
            @rmdir($this->tempDirectory);
        }
    }
}