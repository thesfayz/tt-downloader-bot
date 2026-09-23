<?php

declare(strict_types=1);

namespace App\Services\Url;

final class TikTokIdExtractor
{
    /**
     * @return array{type: string, id: string}|null
     */
    public function extract(string $url): ?array
    {
        if (preg_match('/[\/](video|photo)[\/](\d+)/', $url, $matches)) {
            return [
                'type' => $matches[1],
                'id'   => $matches[2],
            ];
        }

        return null;
    }
}