<?php

declare(strict_types=1);

namespace App\Services\Url;

final class TikTokUrlSanitizer
{
    public function sanitize(string $url): string
    {
        $withoutQuery = strtok($url, '?');
        $clean = $withoutQuery !== false ? $withoutQuery : $url;
        
        return str_replace(' ', '%20', trim($clean));
    }
}