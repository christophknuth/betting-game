<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

use BettingGame\Domain\Repository\CommandLogRepositoryInterface;
use BettingGame\Infrastructure\Auth\AuthMiddleware;
use BettingGame\Presentation\Router\Router;
use FastRoute\Dispatcher;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Request in, response out.
 *
 * Everything the front controller used to do inline lives here, so the whole
 * chain - routing, authentication, the role gate, the command log and the
 * exception mapping - can be exercised without a web server. index.php is then
 * only the bridge between PHP's globals and this.
 */
final class Kernel
{
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    public function __construct(
        private ContainerInterface $container,
        private Router $router,
        private AuthMiddleware $auth,
        private ErrorMapper $errors,
        private CommandLogRepositoryInterface $commandLog
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

        if (($handler['command'] ?? false) === true) {
            return $this->executeCommand($handler, $request, $vars);
        }

        return $this->invoke($handler, $request, $vars);
    }

    /**
     * Runs a command under the command log (OPS-01) and the idempotency key
     * (OPS-02).
     *
     * The key is claimed before the command runs. Checking for a previous
     * attempt and only then executing would leave a window in which two
     * concurrent retries both pass the check and both do the work - which is
     * exactly the double booking the key exists to prevent.
     *
     * @param array<string, mixed>  $handler
     * @param array<string, string> $vars
     */
    private function executeCommand(array $handler, Request $request, array $vars): JsonResponse
    {
        $commandType = $this->commandType($handler);
        $idempotencyKey = $request->header(self::IDEMPOTENCY_HEADER);
        $commandId = Uuid::uuid4()->toString();

        if (!$this->commandLog->claim($commandId, $commandType, $idempotencyKey)) {
            return $this->replay($idempotencyKey);
        }

        try {
            $response = $this->invoke($handler, $request, $vars);
        } catch (Throwable $e) {
            $mapped = $this->errors->toResponse($e);
            $this->commandLog->markFailed($commandId, $mapped->statusCode(), $e->getMessage());

            return $mapped;
        }

        // The handler minted its own id; the log's primary key is the one a
        // caller can look up, so that is the one the caller gets to see.
        $response = $response->withData(['commandId' => $commandId]);

        $resourceId = $response->data()['resourceId'] ?? null;

        $this->commandLog->markCompleted(
            $commandId,
            $response->statusCode(),
            json_encode($response->data(), JSON_THROW_ON_ERROR),
            is_int($resourceId) ? $resourceId : null
        );

        return $response;
    }

    /**
     * Returns what the first attempt with this key produced.
     */
    private function replay(?string $idempotencyKey): JsonResponse
    {
        if ($idempotencyKey === null) {
            throw new RuntimeException('A command was rejected without an idempotency key');
        }

        $previous = $this->commandLog->findByIdempotencyKey($idempotencyKey);

        if ($previous === null) {
            // Claimed and gone again between the two statements. Rare enough to
            // just tell the caller to retry rather than guess what happened.
            return JsonResponse::conflict('This idempotency key is being processed, retry shortly');
        }

        $body = $previous['response_body'] ?? null;
        $status = $previous['http_status'] ?? null;

        if (!is_string($body) || !is_int($status)) {
            return JsonResponse::conflict(
                'A command with this idempotency key is still processing or failed'
            );
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return JsonResponse::conflict('The stored response for this idempotency key is unreadable');
        }

        /** @var array<string, mixed> $decoded */
        return JsonResponse::of($status, $decoded)->withHeader('Idempotent-Replay', 'true');
    }

    /** @param array<string, mixed> $handler */
    private function commandType(array $handler): string
    {
        $controller = $handler['controller'] ?? 'Unknown';
        $method = $handler['method'] ?? 'unknown';

        return (is_string($controller) ? $controller : 'Unknown')
            . '::'
            . (is_string($method) ? $method : 'unknown');
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
