<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\DI;

use BettingGame\Domain\Repository\EventStoreInterface;
use BettingGame\Domain\Repository\ParticipantRepositoryInterface;
use BettingGame\Infrastructure\Auth\AuthMiddleware;
use BettingGame\Infrastructure\Auth\KeycloakService;
use BettingGame\Infrastructure\Cache\FileCache;
use BettingGame\Infrastructure\Config\Config;
use BettingGame\Infrastructure\EventStore\PdoEventStore;
use BettingGame\Infrastructure\Logging\LoggerFactory;
use BettingGame\Infrastructure\Persistence\Db;
use BettingGame\Infrastructure\Persistence\ParticipantRepository;
use BettingGame\Presentation\Controller\HealthController;
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

            // Keycloak Service
            KeycloakService::class => function () use ($settings) {
                return new KeycloakService(
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

            // Controllers
            HealthController::class => \DI\autowire(),

            // Router
            Router::class => \DI\autowire(),
        ]);

        return new PsrContainer($builder->build());
    }
}
