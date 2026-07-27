<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\DI;

use BettingGame\Domain\Repository\PredictionRepositoryInterface;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\Repository\GameEventRepositoryInterface;
use BettingGame\Domain\Repository\BettingGameRepositoryInterface;
use BettingGame\Domain\Repository\ResultRepositoryInterface;
use BettingGame\Application\Query\AdminPredictionReadModelRepositoryInterface;
use BettingGame\Application\Query\BettingGameReadModelRepositoryInterface;
use BettingGame\Application\Query\LeaderboardReadModelRepositoryInterface;
use BettingGame\Application\Query\ParticipantReadModelRepositoryInterface;
use BettingGame\Application\Query\ParticipationReadModelRepositoryInterface;
use BettingGame\Application\Query\PredictionReadModelRepositoryInterface;
use BettingGame\Application\Query\ScoreReadModelRepositoryInterface;
use BettingGame\Application\Command\ApproveParticipantHandler;
use BettingGame\Application\Command\AwardScoreHandler;
use BettingGame\Application\Command\CalculateScoresHandler;
use BettingGame\Application\Command\CreateBettingGameHandler;
use BettingGame\Application\Command\CreateParticipantHandler;
use BettingGame\Application\Command\EndGameHandler;
use BettingGame\Application\Command\JoinGameHandler;
use BettingGame\Application\Command\LeaveGameHandler;
use BettingGame\Application\Command\RecordResultHandler;
use BettingGame\Application\Command\SubmitPredictionHandler;
use BettingGame\Application\Command\UpdatePredictionHandler;
use BettingGame\Application\Command\UpdateResultHandler;
use BettingGame\Application\Query\GetAllGamesHandler;
use BettingGame\Application\Query\GetAllPredictionsHandler;
use BettingGame\Application\Query\GetLeaderboardHandler;
use BettingGame\Application\Query\GetParticipantPredictionsHandler;
use BettingGame\Application\Query\GetParticipantScoresHandler;
use BettingGame\Application\Query\GetParticipationsHandler;
use BettingGame\Infrastructure\EventStore\PdoEventStore;
use BettingGame\Infrastructure\Persistence\PredictionRepository;
use BettingGame\Infrastructure\Persistence\ParticipantRepository;
use BettingGame\Infrastructure\Config\Config;
use BettingGame\Infrastructure\Persistence\AdminPredictionReadModelRepository;
use BettingGame\Infrastructure\Persistence\BettingGameReadModelRepository;
use BettingGame\Infrastructure\Persistence\BettingGameRepository;
use BettingGame\Infrastructure\Persistence\Db;
use BettingGame\Infrastructure\Persistence\GameEventRepository;
use BettingGame\Infrastructure\Persistence\ParticipantReadModelRepository;
use BettingGame\Infrastructure\Persistence\ParticipationReadModelRepository;
use BettingGame\Infrastructure\Persistence\LeaderboardReadModelRepository;
use BettingGame\Infrastructure\Persistence\PredictionReadModelRepository;
use BettingGame\Infrastructure\Persistence\ResultRepository;
use BettingGame\Infrastructure\Persistence\ScoreReadModelRepository;
use BettingGame\Infrastructure\Logging\LoggerFactory;
use BettingGame\Infrastructure\Cache\FileCache;
use BettingGame\Infrastructure\Auth\KeycloakService;
use BettingGame\Infrastructure\Auth\AuthMiddleware;
use BettingGame\Presentation\Controller\AdminGameController;
use BettingGame\Presentation\Controller\AdminParticipantController;
use BettingGame\Presentation\Controller\AdminPredictionController;
use BettingGame\Presentation\Controller\AdminResultController;
use BettingGame\Presentation\Controller\ParticipationController;
use BettingGame\Presentation\Controller\PredictionController;
use BettingGame\Presentation\Controller\ScoreController;
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
                // Use Redis in production, File cache in development
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

            // Keycloak Service
            KeycloakService::class => function () use ($settings) {
                return new KeycloakService(
                    keycloakUrl: $settings->string('keycloak.url', 'http://keycloak:8080'),
                    realm: $settings->string('keycloak.realm', 'betting-game')
                );
            },

            // Auth Middleware
            AuthMiddleware::class => \DI\autowire(),

            // Typed PDO wrapper used by every repository
            Db::class => \DI\autowire(),

            // Event Store
            EventStoreInterface::class => \DI\autowire(PdoEventStore::class),

            // Domain Repositories
            PredictionRepositoryInterface::class => \DI\autowire(PredictionRepository::class),
            ParticipantRepositoryInterface::class => \DI\autowire(ParticipantRepository::class),
            GameEventRepositoryInterface::class => \DI\autowire(GameEventRepository::class),
            ResultRepositoryInterface::class => \DI\autowire(ResultRepository::class),
            BettingGameRepositoryInterface::class => \DI\autowire(BettingGameRepository::class),

            // Read Model Repositories
            PredictionReadModelRepositoryInterface::class => \DI\autowire(PredictionReadModelRepository::class),
            ScoreReadModelRepositoryInterface::class => \DI\autowire(ScoreReadModelRepository::class),
            LeaderboardReadModelRepositoryInterface::class => \DI\autowire(LeaderboardReadModelRepository::class),
            BettingGameReadModelRepositoryInterface::class => \DI\autowire(BettingGameReadModelRepository::class),
            ParticipationReadModelRepositoryInterface::class => \DI\autowire(ParticipationReadModelRepository::class),
            ParticipantReadModelRepositoryInterface::class => \DI\autowire(ParticipantReadModelRepository::class),
            AdminPredictionReadModelRepositoryInterface::class
                => \DI\autowire(AdminPredictionReadModelRepository::class),

            // Command Handlers
            SubmitPredictionHandler::class => \DI\autowire(),
            UpdatePredictionHandler::class => \DI\autowire(),
            RecordResultHandler::class => \DI\autowire(),
            UpdateResultHandler::class => \DI\autowire(),
            CalculateScoresHandler::class => \DI\autowire(),
            AwardScoreHandler::class => \DI\autowire(),
            CreateParticipantHandler::class => \DI\autowire(),
            ApproveParticipantHandler::class => \DI\autowire(),
            CreateBettingGameHandler::class => \DI\autowire(),
            EndGameHandler::class => \DI\autowire(),
            JoinGameHandler::class => \DI\autowire(),
            LeaveGameHandler::class => \DI\autowire(),

            // Query Handlers
            GetParticipantPredictionsHandler::class => \DI\autowire(),
            GetParticipantScoresHandler::class => \DI\autowire(),
            GetLeaderboardHandler::class => \DI\autowire(),
            GetAllGamesHandler::class => \DI\autowire(),
            GetAllPredictionsHandler::class => \DI\autowire(),
            GetParticipationsHandler::class => \DI\autowire(),

            // Controllers
            PredictionController::class => \DI\autowire(),
            ScoreController::class => \DI\autowire(),
            AdminResultController::class => \DI\autowire(),
            AdminParticipantController::class => \DI\autowire(),
            AdminGameController::class => \DI\autowire(),
            AdminPredictionController::class => \DI\autowire(),
            ParticipationController::class => \DI\autowire(),

            // Router
            Router::class => \DI\autowire(),
        ]);

        $phpDiContainer = $builder->build();

        // Return PSR-11 compliant container
        return new PsrContainer($phpDiContainer);
    }
}
