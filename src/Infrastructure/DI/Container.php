<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\DI;

use BettingGame\Domain\Repository\PredictionRepositoryInterface;
use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Domain\Repository\GameEventRepositoryInterface;
use BettingGame\Application\Query\PredictionReadModelRepositoryInterface;
use BettingGame\Application\Query\ScoreReadModelRepositoryInterface;
use BettingGame\Application\Command\SubmitPredictionHandler;
use BettingGame\Application\Command\UpdatePredictionHandler;
use BettingGame\Application\Query\GetParticipantPredictionsHandler;
use BettingGame\Application\Query\GetParticipantScoresHandler;
use BettingGame\Infrastructure\EventStore\PdoEventStore;
use BettingGame\Infrastructure\Persistence\PredictionRepository;
use BettingGame\Infrastructure\Persistence\ParticipantRepository;
use BettingGame\Infrastructure\Persistence\GameEventRepository;
use BettingGame\Infrastructure\Persistence\PredictionReadModelRepository;
use BettingGame\Infrastructure\Persistence\ScoreReadModelRepository;
use BettingGame\Infrastructure\Logging\LoggerFactory;
use BettingGame\Infrastructure\Cache\FileCache;
use BettingGame\Infrastructure\Auth\KeycloakService;
use BettingGame\Infrastructure\Auth\AuthMiddleware;
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
    public static function build(array $config): PsrContainerInterface
    {
        $builder = new ContainerBuilder();
        
        if ($config['production'] ?? false) {
            $builder->enableCompilation(__DIR__ . '/../../../var/cache');
            $builder->enableDefinitionCache();
        }

        $builder->addDefinitions([
            // Database
            PDO::class => function () use ($config) {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $config['db']['host'] ?? 'localhost',
                    $config['db']['port'] ?? 3306,
                    $config['db']['database'] ?? 'betting_game'
                );

                $pdo = new PDO(
                    $dsn,
                    $config['db']['username'] ?? 'root',
                    $config['db']['password'] ?? '',
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );

                return $pdo;
            },

            // PSR-3: Logger Interface
            LoggerInterface::class => function () use ($config) {
                $environment = $config['environment'] ?? 'development';
                return LoggerFactory::createApplicationLogger($environment);
            },

            // PSR-16: Simple Cache Interface
            CacheInterface::class => function () use ($config) {
                // Use Redis in production, File cache in development
                if (($config['cache']['driver'] ?? 'file') === 'redis' && extension_loaded('redis')) {
                    return new \BettingGame\Infrastructure\Cache\RedisCache(
                        host: $config['cache']['redis']['host'] ?? '127.0.0.1',
                        port: $config['cache']['redis']['port'] ?? 6379,
                        prefix: 'betting_game:',
                        defaultTtl: $config['cache']['ttl'] ?? 3600,
                        password: $config['cache']['redis']['password'] ?? null
                    );
                }

                return new FileCache(
                    cacheDir: $config['cache']['path'] ?? null,
                    defaultTtl: $config['cache']['ttl'] ?? 3600
                );
            },

            // Keycloak Service
            KeycloakService::class => function () use ($config) {
                return new KeycloakService(
                    keycloakUrl: $config['keycloak']['url'] ?? 'http://keycloak:8080',
                    realm: $config['keycloak']['realm'] ?? 'betting-game'
                );
            },

            // Auth Middleware
            AuthMiddleware::class => \DI\autowire(),

            // Event Store
            EventStoreInterface::class => \DI\autowire(PdoEventStore::class),

            // Domain Repositories
            PredictionRepositoryInterface::class => \DI\autowire(PredictionRepository::class),
            ParticipantRepositoryInterface::class => \DI\autowire(ParticipantRepository::class),
            GameEventRepositoryInterface::class => \DI\autowire(GameEventRepository::class),

            // Read Model Repositories
            PredictionReadModelRepositoryInterface::class => \DI\autowire(PredictionReadModelRepository::class),
            ScoreReadModelRepositoryInterface::class => \DI\autowire(ScoreReadModelRepository::class),

            // Command Handlers
            SubmitPredictionHandler::class => \DI\autowire(),
            UpdatePredictionHandler::class => \DI\autowire(),

            // Query Handlers
            GetParticipantPredictionsHandler::class => \DI\autowire(),
            GetParticipantScoresHandler::class => \DI\autowire(),

            // Controllers
            PredictionController::class => \DI\autowire(),
            ScoreController::class => \DI\autowire(),

            // Router
            Router::class => \DI\autowire(),
        ]);

        $phpDiContainer = $builder->build();

        // Return PSR-11 compliant container
        return new PsrContainer($phpDiContainer);
    }
}
