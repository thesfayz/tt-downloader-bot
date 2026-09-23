<?php

declare(strict_types=1);

namespace App\Contracts;

interface MessageSenderInterface
{
    public function sendMessage(int $chatId, string $text): void;
    public function sendPhoto(int $chatId, string $filePath, string $caption = ''): void;
    /**
     * @param string[] $filePaths
     */
    public function sendMediaGroup(int $chatId, array $filePaths, string $caption = ''): void;
    public function sendVideo(int $chatId, string $filePath, string $caption = ''): void;
}