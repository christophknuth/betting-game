<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Infrastructure\EventStore\PdoEventStore;
use BettingGame\Infrastructure\Persistence\Db;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that need a real database.
 *
 * The repositories are almost entirely SQL - unique keys, joins and upserts -
 * so a mocked PDO would only assert that we wrote the strings we wrote. These
 * tests run against MariaDB and skip themselves when none is reachable, so the
 * suite stays green on a machine without one.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static ?PDO $pdo = null;
    protected Db $db;
    protected PdoEventStore $eventStore;

    public static function setUpBeforeClass(): void
    {
        if (self::$pdo !== null) {
            return;
        }

        $database = getenv('DB_DATABASE') ?: 'betting_game_test';

        // Every test truncates all 19 tables, so pointing this suite at the
        // development database empties it - silently, and only noticed later
        // when the stack looks freshly installed. The default above is safe;
        // what is not is `docker-compose exec php vendor/bin/phpunit`, because
        // that container carries DB_DATABASE=betting_game for serving.
        //
        // Refusing anything not named *_test turns that data loss into a
        // skipped suite with a note. Use docker-compose.test.yml, which brings
        // its own database.
        if (!str_ends_with($database, '_test')) {
            self::markTestSkipped(sprintf(
                'Refusing to run against "%s": this suite truncates every table, and the '
                . 'name does not end in _test. Run it via docker-compose.test.yml '
                . '(make test-docker), which supplies an isolated database.',
                $database
            ));
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: '127.0.0.1',
            getenv('DB_PORT') ?: '3306',
            $database
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                getenv('DB_USERNAME') ?: 'root',
                getenv('DB_PASSWORD') ?: 'secret',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            self::markTestSkipped('No test database reachable: ' . $e->getMessage());
        }
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            self::markTestSkipped('No test database reachable');
        }

        $this->db = new Db(self::$pdo);
        $this->eventStore = new PdoEventStore($this->db);

        $this->truncateAll();
    }

    /**
     * Empties every table between tests. Order does not matter while the
     * foreign key checks are off, and turning them back on afterwards keeps the
     * constraints under test.
     */
    protected function truncateAll(): void
    {
        $tables = [
            'command_log', 'projection_state', 'snapshot', 'event_stream', 'event_store',
            'payout_share', 'payout', 'fee', 'ticket_row_match', 'ticket_draw_result',
            'draw', 'ticket_row', 'ticket', 'bet_row', 'bet_period', 'membership',
            'tipp_year', 'participant', 'user',
        ];

        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($tables as $table) {
            $this->db->execute("TRUNCATE TABLE $table");
        }

        $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * Participants are a foreign key of almost everything, so most tests need
     * one before they can write anything at all.
     */
    protected function givenParticipant(int $id, string $displayName = 'Tester'): int
    {
        $this->db->execute(
            'INSERT INTO participant (participant_id, display_name, is_active, version) VALUES (?, ?, 1, 0)',
            [$id, $displayName]
        );

        return $id;
    }
}
