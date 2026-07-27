<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Infrastructure\DI\Container;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Base class for tests that exercise the real persistence layer.
 *
 * These tests need a MariaDB/MySQL instance carrying the schema from
 * database/schema.sql. Connection details come from the environment:
 *
 *   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
 *
 * When no database is reachable the whole suite is skipped rather than failed -
 * a missing test database is an environment gap, not a broken build.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected ContainerInterface $container;
    protected PDO $pdo;

    /**
     * Tables in an order that lets TRUNCATE run without tripping foreign keys
     * once checks are disabled; listed explicitly so an unrelated table added
     * later is not silently wiped.
     */
    private const TABLES = [
        'participant_score',
        'prediction',
        'result',
        'event',
        'point_configuration',
        'prize_distribution',
        'game_participation',
        'fee',
        'betting_game',
        'game_type',
        'participant',
        'user',
        'event_store',
        'event_stream',
        'snapshot',
    ];

    protected function setUp(): void
    {
        $config = self::databaseConfig();

        try {
            $this->container = Container::build($config);
            $this->pdo = $this->container->get(PDO::class);
            $this->pdo->query('SELECT 1');
        } catch (Throwable $e) {
            // The container wraps the driver error, so the cause has to be dug out
            // of the chain - anything that is not a connection problem is a real
            // failure and must not be swallowed.
            $connectionError = self::findPdoException($e);

            if ($connectionError === null) {
                throw $e;
            }

            self::markTestSkipped('No test database available: ' . $connectionError->getMessage());
        }

        $this->assertSchemaPresent();
        $this->truncateAll();
    }

    private static function findPdoException(Throwable $e): ?PDOException
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof PDOException) {
                return $current;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function databaseConfig(): array
    {
        return [
            'debug' => true,
            'db' => [
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('DB_PORT') ?: 3306),
                'database' => getenv('DB_DATABASE') ?: 'betting_game_test',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
            ],
            'cache' => [
                'driver' => 'file',
                'path' => sys_get_temp_dir() . '/betting-game-test-cache',
                'ttl' => 60,
            ],
        ];
    }

    private function assertSchemaPresent(): void
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'event_store'"
        );

        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || (int) $row['cnt'] === 0) {
            self::markTestSkipped('Test database has no schema - load database/schema.sql first');
        }
    }

    protected function truncateAll(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::TABLES as $table) {
            $this->pdo->exec("TRUNCATE TABLE $table");
        }

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Minimal fixture: one user, one sports game type.
     */
    protected function seedBaseData(): void
    {
        $this->pdo->exec(
            "INSERT INTO user (user_id, username, password_hash, email)
             VALUES (100, 'alice', 'x', 'alice@example.com'),
                    (101, 'bob', 'x', 'bob@example.com')"
        );
        $this->pdo->exec(
            "INSERT INTO game_type (game_type_id, type_name, category)
             VALUES (1, 'Football', 'sports'), (2, 'Lotto', 'lottery')"
        );
    }

    protected function seedGame(int $id = 5, float $baseFee = 10.00): void
    {
        $this->pdo->exec(
            "INSERT INTO betting_game
                (betting_game_id, name, description, game_type_id, start_date, end_date, base_fee)
             VALUES ($id, 'Test Cup', 'A cup', 1, '2026-01-01 00:00:00', '2026-12-31 00:00:00', $baseFee)"
        );
    }

    /**
     * @param string $deadline defaults to the future so predictions stay editable
     */
    protected function seedEvent(int $id = 42, int $gameId = 5, string $deadline = '2099-06-01 19:00:00'): void
    {
        $this->pdo->exec(
            "INSERT INTO event (event_id, betting_game_id, event_name, event_date, deadline)
             VALUES ($id, $gameId, 'Final', '2026-06-01 20:00:00', '$deadline')"
        );
    }

    protected function seedParticipant(
        int $id = 1,
        int $userId = 100,
        string $name = 'Alice',
        bool $active = true
    ): void {
        $isActive = $active ? 1 : 0;
        $this->pdo->exec(
            "INSERT INTO participant (participant_id, user_id, display_name, is_active)
             VALUES ($id, $userId, '$name', $isActive)"
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function fetchRow(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row, "Expected a row for: $sql");

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchAll(string $sql): array
    {
        $stmt = $this->pdo->query($sql);

        if ($stmt === false) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    protected function countRows(string $table, string $where = '1 = 1'): int
    {
        return (int) $this->fetchRow("SELECT COUNT(*) AS cnt FROM $table WHERE $where")['cnt'];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function get(string $class): object
    {
        /** @var T $service */
        $service = $this->container->get($class);

        return $service;
    }
}
