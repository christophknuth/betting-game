<?php

/**
 * Entry point for the read-only demo. The routing lives in DemoApp.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/DemoApp.php';

use BettingGame\Demo\DemoApp;
use BettingGame\Infrastructure\DI\Container;

$config = [
    'debug' => true,
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_DATABASE') ?: 'betting_game',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: 'secret',
    ],
    'cache' => ['driver' => 'file', 'path' => sys_get_temp_dir() . '/betting-demo', 'ttl' => 60],
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);

/** @var array<string, mixed> $query */
$query = $_GET;

try {
    $app = new DemoApp(Container::build($config));
    [$status, $payload] = $app->handle(
        is_string($method) ? $method : 'GET',
        is_string($path) ? $path : '/',
        $query
    );
} catch (Throwable $e) {
    $status = 503;
    $payload = ['error' => 'Database unavailable', 'message' => $e->getMessage()];
}

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
