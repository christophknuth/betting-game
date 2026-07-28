<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

use BettingGame\Infrastructure\Auth\AuthMiddleware;
use BettingGame\Presentation\Router\Router;
use FastRoute\Dispatcher;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Request in, response out.
 *
 * Everything the front controller used to do inline lives here, so the whole
 * chain - routing, authentication, the role gate and the exception mapping -
 * can be exercised without a web server. index.php is then only the bridge
 * between PHP's globals and this.
 */
final class Kernel
{
    public function __construct(
        private ContainerInterface $container,
        private Router $router,
        private AuthMiddleware $auth,
        private ErrorMapper $errors
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        try {
            return $this->dispatch($request);
        } catch (Throwable $e) {
            return $this->errors->toResponse($e);
        }
    }

    private function dispatch(Request $request): JsonResponse
    {
        $routeInfo = $this->router->dispatch($request->method(), $request->uri());
        $status = $routeInfo[0] ?? Dispatcher::NOT_FOUND;

        if ($status === Dispatcher::NOT_FOUND) {
            return JsonResponse::notFound('Route not found');
        }

        if ($status === Dispatcher::METHOD_NOT_ALLOWED) {
            return JsonResponse::methodNotAllowed($this->allowedMethods($routeInfo[1] ?? []));
        }

        $handler = $routeInfo[1] ?? null;
        $vars = $routeInfo[2] ?? [];

        if (!is_array($handler) || !is_array($vars)) {
            throw new RuntimeException('Malformed route definition');
        }

        /** @var array<string, mixed> $handler */
        /** @var array<string, string> $vars */

        // A route is authenticated unless it says otherwise. Defaulting the
        // other way round would make a forgotten flag silently public.
        if (($handler['public'] ?? false) !== true) {
            $unauthorized = $this->auth->handle($request);

            if ($unauthorized !== null) {
                return $unauthorized;
            }
        }

        if (($handler['role'] ?? null) === Authorization::ADMIN_ROLE) {
            Authorization::requireAdmin($request);
        }

        return $this->invoke($handler, $request, $vars);
    }

    /**
     * @param array<string, mixed>  $handler
     * @param array<string, string> $vars
     */
    private function invoke(array $handler, Request $request, array $vars): JsonResponse
    {
        $name = $handler['controller'] ?? null;
        $method = $handler['method'] ?? null;

        if (!is_string($name) || !is_string($method)) {
            throw new RuntimeException('Route is missing its controller or method');
        }

        $controller = $this->container->get('BettingGame\\Presentation\\Controller\\' . $name);

        if (!is_object($controller) || !method_exists($controller, $method)) {
            throw new RuntimeException("Controller $name has no method $method");
        }

        $callable = [$controller, $method];

        if (!is_callable($callable)) {
            throw new RuntimeException("$name::$method is not callable");
        }

        $response = $callable($request, $vars);

        if (!$response instanceof JsonResponse) {
            throw new RuntimeException("$name::$method did not return a JsonResponse");
        }

        return $response;
    }

    /** @return list<string> */
    private function allowedMethods(mixed $methods): array
    {
        if (!is_array($methods)) {
            return [];
        }

        $allowed = [];
        foreach ($methods as $method) {
            if (is_string($method)) {
                $allowed[] = $method;
            }
        }

        return $allowed;
    }
}
