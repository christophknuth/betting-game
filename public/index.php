<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BettingGame\Infrastructure\DI\Container;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Kernel;
use BettingGame\Presentation\Http\Request;

$config = require __DIR__ . '/../config/config.php';

try {
    $container = Container::build($config);
    $kernel = $container->get(Kernel::class);
} catch (Throwable $e) {
    // The container failed, so there is no ErrorMapper to ask. Nothing about
    // the cause may leak here - a failure at this point is usually a bad
    // database DSN or credentials.
    error_log('Bootstrap failed: ' . $e->getMessage());
    JsonResponse::internalError()->send();

    return;
}

if (!$kernel instanceof Kernel) {
    error_log('Container returned no Kernel');
    JsonResponse::internalError()->send();

    return;
}

$kernel->handle(Request::fromGlobals())->send();
