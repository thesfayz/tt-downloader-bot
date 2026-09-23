<?php

declare(strict_types=1);

namespace App\Contracts;

interface UpdateReceiverInterface
{
    /**
     * @return array<int, mixed>
     */
    public function getUpdates(int $offset, int $timeout = 15): array;
}