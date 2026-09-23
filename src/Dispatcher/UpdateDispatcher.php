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
        if (!isset($update['message']['text'])) {
            return;
        }

        $chatId = (int)$update['message']['chat']['id'];
        $text = trim($update['message']['text']);

        foreach ($this->handlers as $handler) {
            if ($handler->supports($text)) {
                $handler->handle($chatId, $text);
                return;
            }
        }
    }
}