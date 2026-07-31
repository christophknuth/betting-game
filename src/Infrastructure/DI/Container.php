<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\DI;

use BettingGame\Application\Command;
use BettingGame\Application\Projection\ProjectionManager;
use BettingGame\Application\Query;
use BettingGame\Domain\Repository\CommandLogRepositoryInterface;
use BettingGame\Domain\Repository\ProjectionStateRepositoryInterface;
use BettingGame\Domain\Repository\BetPeriodRepositoryInterface;
use BettingGame\Domain\Repository\BetRowRepositoryInterface;
use BettingGame\Domain\Repository\DrawRepositoryInterface;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\Repository\FeeRepositoryInterface;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\Repository\TicketRepositoryInterface;
use BettingGame\Domain\Repository\TippYearRepositoryInterface;
use BettingGame\Infrastructure\Auth\AuthMiddleware;
use BettingGame\Infrastructure\Auth\CurlFetcher;
use BettingGame\Infrastructure\Auth\KeycloakKeys;
use BettingGame\Infrastructure\Auth\KeycloakService;
use BettingGame\Infrastructure\Auth\KeySource;
use BettingGame\Infrastructure\Auth\StaticKeys;
use BettingGame\Infrastructure\Auth\TokenVerifier;
use BettingGame\Infrastructure\Cache\FileCache;
use BettingGame\Infrastructure\Config\Config;
use BettingGame\Infrastructure\EventStore\PdoEventStore;
use BettingGame\Infrastructure\Logging\LoggerFactory;
use BettingGame\Infrastructure\Persistence\BetPeriodRepository;
use BettingGame\Infrastructure\Persistence\BetRowRepository;
use BettingGame\Infrastructure\Persistence\CommandLogRepository;
use BettingGame\Infrastructure\Persistence\Db;
use BettingGame\Infrastructure\Persistence\ProjectionStateRepository;
use BettingGame\Infrastructure\Projection;
use BettingGame\Infrastructure\Persistence\DrawRepository;
use BettingGame\Infrastructure\Persistence\FeeRepository;
use BettingGame\Infrastructure\Persistence\ParticipantRepository;
use BettingGame\Infrastructure\Persistence\TicketRepository;
use BettingGame\Infrastructure\Persistence\TippYearRepository;
use BettingGame\Presentation\Controller;
use BettingGame\Presentation\Controller\HealthController;
use BettingGame\Presentation\Http\ErrorMapper;
use BettingGame\Presentation\Http\Kernel;
use BettingGame\Presentation\Router\Router;
use DI\ContainerBuilder;
use PDO;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

final class Container
{
    /**
     * @param array<string, mixed> $config
     */
    public static function build(array $config): PsrContainerInterface
    {
        $settings = new Config($config);
        $builder = new ContainerBuilder();

        if ($settings->bool('production')) {
            $builder->enableCompilation(__DIR__ . '/../../../var/cache');
            $builder->enableDefinitionCache();
        }

        $builder->addDefinitions([
            // Database
            PDO::class => function () use ($settings) {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $settings->string('db.host', 'localhost'),
                    $settings->int('db.port', 3306),
                    $settings->string('db.database', 'betting_game')
                );

                return new PDO(
                    $dsn,
                    $settings->string('db.username', 'root'),
                    $settings->string('db.password', ''),
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            },

            // PSR-3: Logger Interface
            LoggerInterface::class => function () use ($settings) {
                return LoggerFactory::createApplicationLogger(
                    $settings->string('environment', 'development')
                );
            },

            // PSR-16: Simple Cache Interface
            CacheInterface::class => function () use ($settings) {
                if ($settings->string('cache.driver', 'file') === 'redis' && extension_loaded('redis')) {
                    return new \BettingGame\Infrastructure\Cache\RedisCache(
                        host: $settings->string('cache.redis.host', '127.0.0.1'),
                        port: $settings->int('cache.redis.port', 6379),
                        prefix: 'betting_game:',
                        defaultTtl: $settings->int('cache.ttl', 3600),
                        password: $settings->nullableString('cache.redis.password')
                    );
                }

                return new FileCache(
                    cacheDir: $settings->nullableString('cache.path'),
                    defaultTtl: $settings->int('cache.ttl', 3600)
                );
            },

            // Where the public signing keys come from. A statically configured
            // key set wins when there is one, so a deployment that cannot reach
            // Keycloak at request time still verifies signatures rather than
            // falling back to trusting the token.
            KeySource::class => static function (PsrContainerInterface $c) use ($settings): KeySource {
                $configured = $settings->nullableString('keycloak.jwks');

                if ($configured !== null && trim($configured) !== '') {
                    return StaticKeys::from($configured);
                }

                $cache = $c->get(CacheInterface::class);
                assert($cache instanceof CacheInterface);

                return new KeycloakKeys(
                    jwksUrl: $settings->string('keycloak.jwks_url'),
                    fetcher: new CurlFetcher(),
                    cache: $cache,
                    ttlSeconds: $settings->int('keycloak.jwks_ttl', 300)
                );
            },

            TokenVerifier::class => static function (PsrContainerInterface $c) use ($settings): TokenVerifier {
                $keys = $c->get(KeySource::class);
                assert($keys instanceof KeySource);

                return new TokenVerifier(
                    keys: $keys,
                    issuer: $settings->string('keycloak.issuer'),
                    audience: $settings->nullableString('keycloak.audience'),
                    leewaySeconds: $settings->int('keycloak.leeway', 30)
                );
            },

            // Keycloak Service
            KeycloakService::class => static function (PsrContainerInterface $c) use ($settings): KeycloakService {
                $verifier = $c->get(TokenVerifier::class);
                assert($verifier instanceof TokenVerifier);

                return new KeycloakService(
                    verifier: $verifier,
                    keycloakUrl: $settings->string('keycloak.url', 'http://keycloak:8080'),
                    realm: $settings->string('keycloak.realm', 'betting-game')
                );
            },

            AuthMiddleware::class => \DI\autowire(),

            // Typed PDO wrapper used by every repository
            Db::class => \DI\autowire(),

            // Event Store
            EventStoreInterface::class => \DI\autowire(PdoEventStore::class),

            // Domain Repositories
            ParticipantRepositoryInterface::class => \DI\autowire(ParticipantRepository::class),
            TippYearRepositoryInterface::class => \DI\autowire(TippYearRepository::class),
            BetPeriodRepositoryInterface::class => \DI\autowire(BetPeriodRepository::class),
            BetRowRepositoryInterface::class => \DI\autowire(BetRowRepository::class),
            TicketRepositoryInterface::class => \DI\autowire(TicketRepository::class),
            DrawRepositoryInterface::class => \DI\autowire(DrawRepository::class),
            FeeRepositoryInterface::class => \DI\autowire(FeeRepository::class),
            CommandLogRepositoryInterface::class => \DI\autowire(CommandLogRepository::class),
            ProjectionStateRepositoryInterface::class => \DI\autowire(ProjectionStateRepository::class),

            // Projections (OPS-04). The manager takes every projector, so a new
            // read model only has to be added to this list.
            ProjectionManager::class => static function (PsrContainerInterface $c): ProjectionManager {
                $db = $c->get(Db::class);
                $eventStore = $c->get(EventStoreInterface::class);
                $state = $c->get(ProjectionStateRepositoryInterface::class);

                if (
                    !$db instanceof Db
                    || !$eventStore instanceof EventStoreInterface
                    || !$state instanceof ProjectionStateRepositoryInterface
                ) {
                    throw new ContainerException('Cannot build the projection manager');
                }

                return new ProjectionManager($eventStore, $state, [
                    new Projection\ParticipantProjector($db),
                    new Projection\TippYearProjector($db),
                    new Projection\BetPeriodProjector($db),
                    new Projection\BetRowProjector($db),
                    new Projection\TicketProjector($db),
                    new Projection\DrawProjector($db),
                    new Projection\FeeProjector($db),
                ]);
            },

            // Command handlers - autowired from the repository interfaces above
            Command\AddMemberHandler::class => \DI\autowire(),
            Command\AssignBetRowHandler::class => \DI\autowire(),
            Command\ChangeTippYearStatusHandler::class => \DI\autowire(),
            Command\CreateBetPeriodHandler::class => \DI\autowire(),
            Command\CreateParticipantHandler::class => \DI\autowire(),
            Command\CreateTippYearHandler::class => \DI\autowire(),
            Command\DistributePayoutHandler::class => \DI\autowire(),
            Command\RecordDrawHandler::class => \DI\autowire(),
            Command\RecordDrawWinningsHandler::class => \DI\autowire(),
            Command\RecordFeePaymentHandler::class => \DI\autowire(),
            Command\SubmitTicketHandler::class => \DI\autowire(),

            // Query handlers
            Query\GetBetPeriodsHandler::class => \DI\autowire(),
            Query\GetBetRowHandler::class => \DI\autowire(),
            Query\GetDrawsHandler::class => \DI\autowire(),
            Query\GetFeesHandler::class => \DI\autowire(),
            Query\GetMembershipsHandler::class => \DI\autowire(),
            Query\GetParticipantFeesHandler::class => \DI\autowire(),
            Query\GetParticipantsHandler::class => \DI\autowire(),
            Query\GetPayoutShareHandler::class => \DI\autowire(),
            Query\GetTippYearsHandler::class => \DI\autowire(),
            Query\GetCommandStatusHandler::class => \DI\autowire(),
            Query\GetAuditTrailHandler::class => \DI\autowire(),

            // Controllers
            HealthController::class => \DI\autowire(),
            Controller\ParticipantController::class => \DI\autowire(),
            Controller\TippYearController::class => \DI\autowire(),
            Controller\AdminBetRowController::class => \DI\autowire(),
            Controller\AdminDrawController::class => \DI\autowire(),
            Controller\AdminFeeController::class => \DI\autowire(),
            Controller\AdminParticipantController::class => \DI\autowire(),
            Controller\AdminTippYearController::class => \DI\autowire(),
            Controller\CommandStatusController::class => \DI\autowire(),
            Controller\AdminOperationsController::class => \DI\autowire(),

            // HTTP
            Router::class => \DI\autowire(),

            // Only debug builds put an exception message in a 500 response
            ErrorMapper::class => fn (): ErrorMapper => new ErrorMapper($settings->bool('debug')),

            Kernel::class => \DI\autowire(),
        ]);

        return new PsrContainer($builder->build());
    }
}
