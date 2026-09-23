<?php

declare(strict_types=1);

namespace App\Services\Url;

use GuzzleHttp\ClientInterface;

final class RedirectResolver
{
    public function __construct(
        private readonly ClientInterface $httpClient
    ) {}

    public function resolve(string $url): string
    {
        if (!str_contains($url, 'vt.tiktok.com') && !str_contains($url, 'vm.tiktok.com')) {
            return $url;
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'allow_redirects' => [
                    'max' => 10,
                    'track_redirects' => true,
                ],
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
                ],
                'timeout' => 10,
            ]);

            $history = $response->getHeader('X-Guzzle-Redirect-History');
            if (!empty($history)) {
                return (string) end($history);
            }
        } catch (\Throwable) {
            // Если сеть отвалилась — возвращаем как есть
        }

        return $url;
    }
}