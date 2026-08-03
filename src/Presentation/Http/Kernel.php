<?php

declare(strict_types=1);

namespace BettingGame\Presentation\Http;

use BettingGame\Domain\Repository\CommandLogRepositoryInterface;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Infrastructure\Auth\AuthMiddleware;
use BettingGame\Presentation\Router\Router;
use FastRoute\Dispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
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
        private CommandLogRepositoryInterface $commandLog,
        private ParticipantRepositoryInterface $participants,
        private LoggerInterface $logger
    ) {
    }

    /**
     * The one place an error is put into the caller's language.
     *
     * At the very edge, and after everything else: the exception, the command
     * log and the container log keep the English wording, so a log line never
     * depends on which browser happened to send the request. Wrapping the whole
     * dispatch rather than the ErrorMapper alone also catches what never
     * reaches it - a rejected token, an unknown route, a method that is not
     * allowed - which are error messages as much as a broken rule is.
     */
    public function handle(Request $request): JsonResponse
    {
        $language = Translator::preferredLanguage($request->header('Accept-Language'));

        try {
            $response = $this->dispatch($request);
        } catch (Throwable $e) {
            $response = $this->errors->toResponse($e);
            $this->logFailure($request, $e, $response);
        }

        return Translator::localise($response, $language);
    }

    /**
     * What the caller is no longer told, written down where it belongs.
     *
     * Commands log their own rejection a few lines further down; this is the
     * other half - queries. A `GET` that ended in a 500 used to leave no trace
     * anywhere at all, and since the response now says "Internal Server Error"
     * and nothing else, without this there would be nothing left to read.
     *
     * Only from 500 up. A 404 or a 409 out of a query is the API answering
     * correctly, and logging those would bury the faults among them.
     */
    private function logFailure(Request $request, Throwable $e, JsonResponse $response): void
    {
        if ($response->statusCode() < 500) {
            return;
        }

        $this->logger->error('Request failed', [
            'method' => $request->method(),
            'uri' => $request->uri(),
            'actor' => $this->actor($request),
            'status' => $response->statusCode(),
            'reason' => $e->getMessage(),
            'exception' => $e::class,
        ]);
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

            $this->resolveParticipant($request);
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
     * E1-01: which participant the token belongs to, where the claim is silent.
     *
     * `participant_id` in the token stays the first source - a realm that maps
     * the attribute keeps working unchanged, and it costs no query. Where it is
     * absent the account's `sub` is looked up in the participant read model,
     * which is what a self-registration wrote there.
     *
     * That lookup is the whole reason self-registration is self-service. Before
     * it, becoming visible to the application meant an administrator opening
     * Keycloak and typing an id into a user attribute; now the registration
     * itself establishes the link.
     *
     * A **pending** participant is deliberately resolved as well. They are not
     * a member of anything and every rule that matters checks the status, but
     * `GET /participants/{id}/…` answering "you are nobody" to somebody whose
     * registration is on an administrator's desk would be a lie.
     *
     * Cross-cutting, so it belongs here rather than in a controller - and
     * outside AuthMiddleware, which decides whether a token is genuine and
     * should not also be reading the database.
     */
    private function resolveParticipant(Request $request): void
    {
        if (is_int($request->attribute('participant_id'))) {
            return;
        }

        $subject = $request->attribute('subject');

        if (!is_string($subject) || $subject === '') {
            return;
        }

        $participant = $this->participants->findByKeycloakSubject($subject);

        if ($participant !== null) {
            $request->setAttribute('participant_id', $participant->id());
        }
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
            $this->logger->info('Command replayed from its idempotency key', [
                'command' => $commandType,
                'actor' => $this->actor($request),
            ]);

            return $this->replay($idempotencyKey);
        }

        try {
            $response = $this->invoke($handler, $request, $vars);
        } catch (Throwable $e) {
            $mapped = $this->errors->toResponse($e);
            $this->commandLog->markFailed($commandId, $mapped->statusCode(), $e->getMessage());

            // Warning, not error: a rejected command is usually a business rule
            // doing its job - a second running tipp year, a duplicate bet row -
            // and the interface deliberately no longer explains which. This is
            // where that goes, with the reason intact.
            //
            // From 500 up it is not a rule saying no but us failing, and that
            // is an error however it arrived.
            $this->logger->log($mapped->statusCode() >= 500 ? 'error' : 'warning', 'Command rejected', [
                'command' => $commandType,
                'commandId' => $commandId,
                'actor' => $this->actor($request),
                'status' => $mapped->statusCode(),
                'reason' => $e->getMessage(),
                'exception' => $e::class,
            ]);

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

        // The commandId used to be printed into the interface on every write,
        // where it meant nothing to the person reading it. It belongs here: the
        // handle for GET /commands/{id}, next to who did what and to which
        // resource.
        $this->logger->info('Command accepted', [
            'command' => $commandType,
            'commandId' => $commandId,
            'actor' => $this->actor($request),
            'status' => $response->statusCode(),
            'resourceId' => is_int($resourceId) ? $resourceId : null,
        ]);

        return $response;
    }

    /**
     * Who issued this, for the log.
     *
     * The username from the verified token - an assertion by Keycloak, not by
     * the caller. Absent only on the public route, which is not a command.
     */
    private function actor(Request $request): string
    {
        $username = $request->attribute('username');

        return is_string($username) ? $username : 'anonymous';
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
