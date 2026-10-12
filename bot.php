<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\BotRunner;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;

if (file_exists(__DIR__ . '/.env')) {
    Dotenv::createImmutable(__DIR__)->load();
}

/** @var ContainerInterface $container */
$container = require __DIR__ . '/config/container.php';

// Контейнер сам собирает всё дерево зависимостей через Reflection
$runner = $container->get(BotRunner::class);
$runner->run();
