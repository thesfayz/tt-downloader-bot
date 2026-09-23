<?php

declare(strict_types=1);

namespace App\Contracts;

interface HandlerInterface
{
    public function supports(string $text): bool;
    public function handle(int $chatId, string $text): void;
}