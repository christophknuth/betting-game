<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Router;

use FastRoute\RouteCollector;
use FastRoute\Dispatcher;

use function FastRoute\simpleDispatcher;

/**
 * Routing table.
 *
 * The sports routes were removed with the move to the Lotto syndicate domain.
 * The Lotto routes (B-01 to B-13) arrive with the application layer; until then
 * only the health check is served.
 */
final class Router
{
    private Dispatcher $dispatcher;

    public function __construct()
    {
        $this->dispatcher = simpleDispatcher(function (RouteCollector $r) {
            $r->addRoute('GET', '/health', [
                'controller' => 'HealthController',
                'method' => 'check'
            ]);
        });
    }

    /**
     * FastRoute result: [NOT_FOUND] | [METHOD_NOT_ALLOWED, list<string>]
     * | [FOUND, array{controller: string, method: string, role?: string}, array<string, string>]
     *
     * @return array<int, mixed>
     */
    public function dispatch(string $httpMethod, string $uri): array
    {
        /** @var array<int, mixed> $routeInfo */
        $routeInfo = $this->dispatcher->dispatch($httpMethod, $uri);

        return $routeInfo;
    }
}
