<?php

declare(strict_types=1);

namespace App\Services\Url;

use App\Contracts\UrlResolverInterface;

final class CanonicalTikTokUrlResolver implements UrlResolverInterface
{
    public function __construct(
        private readonly RedirectResolver $redirectResolver,
        private readonly TikTokIdExtractor $idExtractor,
        private readonly TikTokUrlSanitizer $sanitizer
    ) {}

    public function resolve(string $rawUrl): string
    {
        // 1. Раскрываем короткую ссылку
        $expandedUrl = $this->redirectResolver->resolve($rawUrl);

        // 2. Пробуем достать ID
        $mediaData = $this->idExtractor->extract($expandedUrl);
        if ($mediaData !== null) {
            return "https://www.tiktok.com/@i/video/{$mediaData['id']}";
        }

        // 3. Если ID по шаблону не нашелся — санируем исходный URL
        return $this->sanitizer->sanitize($expandedUrl);
    }
}