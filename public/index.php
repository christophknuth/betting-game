<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BettingGame\Infrastructure\DI\Container;
use BettingGame\Presentation\Router\Router;
use BettingGame\Presentation\Http\Request;
use BettingGame\Presentation\Http\JsonResponse;
use FastRoute\Dispatcher;

// Load configuration
$config = require __DIR__ . '/../config/config.php';

// Build DI container
$container = Container::build($config);

// Create request
$request = Request::fromGlobals();

// Get router
$router = $container->get(Router::class);

// Dispatch request
$routeInfo = $router->dispatch($request->method(), $request->uri());

switch ($routeInfo[0]) {
    case Dispatcher::NOT_FOUND:
        JsonResponse::notFound('Route not found')->send();
        break;

    case Dispatcher::METHOD_NOT_ALLOWED:
        JsonResponse::badRequest('Method not allowed')->send();
        break;

    case Dispatcher::FOUND:
        $handler = $routeInfo[1];
        $vars = $routeInfo[2];

        // Simple JWT authentication simulation
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            // In production: validate JWT token
            // For now: extract participant_id from mock token
            $token = substr($authHeader, 7);
            $request->setAttribute('participant_id', (int) ($vars['participantId'] ?? 1));
            $request->setAttribute('is_admin', str_contains($token, 'admin'));
        }

        // Check admin role if required
        if (isset($handler['role']) && $handler['role'] === 'admin') {
            if (!$request->attribute('is_admin')) {
                JsonResponse::forbidden('Admin access required')->send();
                break;
            }
        }

        try {
            // Get controller from container
            $controller = $container->get('BettingGame\\Presentation\\Controller\\' . $handler['controller']);
            
            // Call controller method
            $method = $handler['method'];
            $response = $controller->$method($request, $vars);
            
            $response->send();
        } catch (\Throwable $e) {
            // Log error in production
            error_log($e->getMessage());
            
            if ($config['debug'] ?? false) {
                JsonResponse::internalError($e->getMessage())->send();
            } else {
                JsonResponse::internalError()->send();
            }
        }
        break;
}
