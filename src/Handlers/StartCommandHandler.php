<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Contracts\HandlerInterface;
use App\Contracts\MessageSenderInterface;

final class StartCommandHandler implements HandlerInterface
{
    public function __construct(
        private readonly MessageSenderInterface $sender
    ) {}

    public function supports(string $text): bool
    {
        return trim($text) === '/start';
    }

    public function handle(int $chatId, string $text): void
    {
        $this->sender->sendMessage(
            $chatId,
            "Привет! Отправь ссылку на TikTok, и я пришлю файлы без водяного знака."
        );
    }
}