<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration\Http;

use BettingGame\Application\Query\GetAuditTrailHandler;
use BettingGame\Application\Query\GetCommandStatusHandler;
use BettingGame\Infrastructure\Auth\AuthMiddleware;
use BettingGame\Infrastructure\Auth\KeycloakService;
use BettingGame\Infrastructure\Auth\StaticKeys;
use BettingGame\Infrastructure\Auth\TokenVerifier;
use BettingGame\Presentation\Controller\AdminBetRowController;
use BettingGame\Presentation\Controller\AdminDrawController;
use BettingGame\Presentation\Controller\AdminFeeController;
use BettingGame\Presentation\Controller\AdminOperationsController;
use BettingGame\Presentation\Controller\AdminTippYearController;
use BettingGame\Presentation\Controller\CommandStatusController;
use BettingGame\Presentation\Controller\HealthController;
use BettingGame\Presentation\Controller\ParticipantController;
use BettingGame\Presentation\Controller\TippYearController;
use BettingGame\Presentation\Http\ErrorMapper;
use BettingGame\Presentation\Http\JsonResponse;
use BettingGame\Presentation\Http\Kernel;
use BettingGame\Presentation\Http\Request;
use BettingGame\Presentation\Router\Router;
use BettingGame\Tests\Integration\Application\ApplicationTestCase;
use BettingGame\Tests\Support\SigningKey;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * A Kernel wired to real controllers, real handlers and a real database.
 *
 * The container here is a hand-built stand-in rather than the application's
 * PHP-DI one: the point of these tests is the request chain, not the wiring,
 * and building it explicitly keeps a test failure pointing at the code under
 * test instead of at a definition file.
 */
abstract class HttpTestCase extends ApplicationTestCase
{
    protected const ISSUER = 'http://keycloak:8080/realms/betting-game';

    protected Kernel $kernel;

    protected function setUp(): void
    {
        parent::setUp();

        $controllers = [
            'BettingGame\Presentation\Controller\HealthController' => new HealthController(),
            'BettingGame\Presentation\Controller\ParticipantController' => new ParticipantController(
                $this->getBetRow(),
                $this->getMemberships(),
                $this->getParticipantFees(),
                $this->getPayoutShare()
            ),
            'BettingGame\Presentation\Controller\TippYearController' => new TippYearController(
                $this->getDraws()
            ),
            'BettingGame\Presentation\Controller\AdminBetRowController' => new AdminBetRowController(
                $this->assignBetRow()
            ),
            'BettingGame\Presentation\Controller\AdminDrawController' => new AdminDrawController(
                $this->recordDraw(),
                $this->recordDrawWinnings()
            ),
            'BettingGame\Presentation\Controller\AdminFeeController' => new AdminFeeController(
                $this->recordFeePayment(),
                $this->getFees()
            ),
            'BettingGame\Presentation\Controller\AdminTippYearController' => new AdminTippYearController(
                $this->createTippYear(),
                $this->createBetPeriod(),
                $this->addMember(),
                $this->submitTicket(),
                $this->distributePayout(),
                $this->getTippYears(),
                $this->getBetPeriods()
            ),
            'BettingGame\Presentation\Controller\CommandStatusController' => new CommandStatusController(
                new GetCommandStatusHandler($this->commandLog, $this->eventStore)
            ),
            'BettingGame\Presentation\Controller\AdminOperationsController' => new AdminOperationsController(
                new GetAuditTrailHandler($this->eventStore),
                $this->projections()
            ),
        ];

        $container = new class ($controllers) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private array $services)
            {
            }

            public function get(string $id): object
            {
                return $this->services[$id] ?? throw new \RuntimeException("Unknown service $id");
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };

        $this->kernel = new Kernel(
            $container,
            new Router(),
            new AuthMiddleware(
                new KeycloakService(new TokenVerifier(
                    keys: new StaticKeys(SigningKey::shared()->jwks()),
                    issuer: self::ISSUER
                )),
                new NullLogger()
            ),
            new ErrorMapper(true),
            $this->commandLog
        );
    }

    /**
     * A token signed with the key the kernel above verifies against.
     *
     * These are real signatures, not fixtures: the suite stands in for a
     * Keycloak rather than for the absence of one. It used to append the
     * literal string "unverified" and get in anyway, which was an accurate
     * description of the application at the time.
     *
     * @param list<string> $roles
     */
    protected function token(?int $participantId, array $roles = []): string
    {
        return SigningKey::shared()->token([
            'iss' => self::ISSUER,
            'sub' => 'participant-' . ($participantId ?? 'unknown'),
            'exp' => time() + 3600,
            'iat' => time(),
            'participant_id' => $participantId,
            'preferred_username' => 'tester',
            'realm_access' => ['roles' => $roles],
        ]);
    }

    /**
     * A token nobody signed - what a caller can produce on their own.
     *
     * @param list<string> $roles
     */
    protected function forgedToken(?int $participantId, array $roles = []): string
    {
        return SigningKey::shared()->unsignedToken([
            'iss' => self::ISSUER,
            'exp' => time() + 3600,
            'participant_id' => $participantId,
            'preferred_username' => 'attacker',
            'realm_access' => ['roles' => $roles],
        ]);
    }

    /** Properly signed, but past its expiry. */
    protected function expiredToken(?int $participantId): string
    {
        return SigningKey::shared()->token([
            'iss' => self::ISSUER,
            'exp' => time() - 3600,
            'iat' => time() - 7200,
            'participant_id' => $participantId,
            'preferred_username' => 'tester',
            'realm_access' => ['roles' => []],
        ]);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    protected function send(
        string $method,
        string $uri,
        ?string $token = null,
        array $body = [],
        array $headers = []
    ): JsonResponse {
        $path = parse_url($uri, PHP_URL_PATH);
        $queryString = parse_url($uri, PHP_URL_QUERY);

        $query = [];
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        $requestHeaders = [];
        foreach ($headers as $name => $value) {
            $requestHeaders[strtoupper(str_replace('-', '_', $name))] = $value;
        }

        if ($token !== null) {
            $requestHeaders['AUTHORIZATION'] = 'Bearer ' . $token;
        }

        return $this->kernel->handle(new Request(
            method: $method,
            uri: is_string($path) ? $path : '/',
            headers: $requestHeaders,
            query: $query,
            body: $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR)
        ));
    }
}
