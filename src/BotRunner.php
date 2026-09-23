<?php

declare(strict_types=1);

namespace App;

use App\Contracts\UpdateReceiverInterface;
use App\Dispatcher\UpdateDispatcher;

final class BotRunner
{
    private int $offset = 0;

    public function __construct(
        private readonly UpdateReceiverInterface $receiver,
        private readonly UpdateDispatcher $dispatcher
    ) {}

    public function run(): never
    {
        echo "Бот запущен...\n";

        while (true) {
            try {
                $updates = $this->receiver->getUpdates($this->offset);

                foreach ($updates as $update) {
                    $this->offset = $update['update_id'] + 1;
                    $this->dispatcher->dispatch($update);
                }
            } catch (\Throwable $e) {
                echo "Ошибка цикла polling: " . $e->getMessage() . "\n";
                sleep(2);
            }

            usleep(500000);
        }
    }
}