<?php

declare(strict_types=1);

namespace App\Dispatcher;

use App\Contracts\HandlerInterface;

final class UpdateDispatcher
{
    /**
     * @param HandlerInterface[] $handlers
     */
    public function __construct(
        private readonly array $handlers
    ) {}

    public function dispatch(array $update): void
    {
        // Поддерживаем и обычные сообщения, и редактированные (edited_message)
        $message = $update['message'] ?? $update['edited_message'] ?? null;

        if (!is_array($message) || !isset($message['text'])) {
            return;
        }

        $chatId = (int)($message['chat']['id'] ?? 0);
        if ($chatId === 0) {
            return;
        }

        $text = trim((string)$message['text']);

        foreach ($this->handlers as $handler) {
            try {
                if ($handler->supports($text)) {
                    $handler->handle($chatId, $text);
                    return;
                }
            } catch (\Throwable $e) {
                echo '[Dispatcher] Ошибка обработчика ' . get_class($handler)
                    . ': ' . $e->getMessage() . "\n";
            }
        }
    }
}
