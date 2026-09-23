<?php

declare(strict_types=1);

namespace App\Contracts;

interface UrlResolverInterface
{
    public function resolve(string $rawUrl): string;
}