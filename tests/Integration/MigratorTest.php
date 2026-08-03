<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Infrastructure\Persistence\Migrator;
use BettingGame\Support\Row;
use BettingGame\Support\SchemaOutOfDateException;

/**
 * Bringing a database that already holds data up to the current schema.
 *
 * This is the mechanism that was missing on 2026-08-03, when a running stack
 * answered "Unknown column 't.duration_weeks'" on "Meine Teilnahmen" and
 * "Column status is missing" on the participant list: `schema.sql` is only read
 * into an empty data directory, so the two features shipped that day had
 * changed nothing in the database that was serving them.
 *
 * The tests below therefore do to the test database what a version switch does
 * to a real one - take the columns away again and let the migrations put them
 * back - and check the error the application gives in between.
 */
final class MigratorTest extends IntegrationTestCase
{
    private Migrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrator = new Migrator($this->db, Migrator::defaultDirectory());
    }

    /**
     * The test database is created from `schema.sql` and is therefore current.
     * Applying the migrations to it must still work, because that is what a
     * fresh installation does - they find everything in place and only write
     * their line.
     */
    public function testAFreshDatabaseRunsThemAllWithoutChangingAnything(): void
    {
        $this->forgetMigrations();

        $before = $this->columnsOf('participant');

        $applied = $this->migrator->migrate();

        self::assertNotEmpty($applied);
        self::assertSame($before, $this->columnsOf('participant'), 'nothing was added twice');
        self::assertSame([], $this->migrator->pending());
    }

    public function testRunningThemAgainAppliesNothing(): void
    {
        $this->forgetMigrations();
        $this->migrator->migrate();

        self::assertSame([], $this->migrator->migrate());
    }

    public function testAMigrationThatHasRunIsWrittenDownWithItsName(): void
    {
        $this->forgetMigrations();
        $this->migrator->migrate();

        $rows = $this->db->fetchAll('SELECT version, name FROM schema_migration ORDER BY version');

        self::assertSame(
            array_map(
                static fn ($migration): string => $migration->version,
                Migrator::discover(Migrator::defaultDirectory())
            ),
            array_map(static fn (array $row): string => Row::string($row, 'version'), $rows)
        );
        self::assertNotSame('', Row::string($rows[0], 'name'));
    }

    /**
     * The database of 2026-08-02, and what the two features did to it.
     */
    public function testAColumnTakenAwayComesBackWithTheMigrations(): void
    {
        $this->db->execute('ALTER TABLE ticket DROP COLUMN duration_weeks');
        $this->forgetMigrations();

        self::assertNotContains('duration_weeks', $this->columnsOf('ticket'));

        $applied = $this->migrator->migrate();

        self::assertNotEmpty($applied);
        self::assertContains('duration_weeks', $this->columnsOf('ticket'));
        self::assertContains('draw_days', $this->columnsOf('ticket'));
    }

    /**
     * What the participant list did before the migration: `SELECT *` succeeds,
     * the row simply has no `status`, and the code that reads it is what
     * notices. That used to be "Column status is missing or null", a sentence
     * about a PHP array; it is now the one thing worth saying.
     */
    public function testUntilThenTheApplicationSaysTheDatabaseIsBehindRatherThanSpeakingSql(): void
    {
        $this->givenParticipant(1, 'Anna');
        $this->db->execute('ALTER TABLE participant DROP COLUMN status');

        try {
            $row = $this->db->fetchOne('SELECT * FROM participant WHERE participant_id = 1');
            self::assertNotNull($row);

            $this->expectException(SchemaOutOfDateException::class);
            $this->expectExceptionMessage(
                'The stored data is not up to date with the application: status is missing'
            );

            Row::string($row, 'status');
        } finally {
            // Whatever the assertions did, the next test needs its schema back
            $this->forgetMigrations();
            $this->migrator->migrate();
        }
    }

    /**
     * And the other way of noticing: a query that names the column outright
     * fails in the driver, in English, with the statement attached. That is the
     * message the browser showed.
     */
    public function testAQueryNamingTheMissingColumnFailsAsTheSameKindOfFault(): void
    {
        $this->db->execute('ALTER TABLE ticket DROP COLUMN duration_weeks');

        try {
            $this->db->fetchAll('SELECT t.duration_weeks FROM ticket t');
            self::fail('the driver should have refused this');
        } catch (SchemaOutOfDateException $e) {
            self::assertSame(
                'The database is not up to date with the application: column duration_weeks is missing',
                $e->getMessage(),
                'the alias and the SQL clause are not part of it'
            );
            self::assertNotNull($e->getPrevious(), 'the driver message stays for the log');
        } finally {
            $this->forgetMigrations();
            $this->migrator->migrate();
        }
    }

    public function testAMissingTableIsTheSameDiagnosis(): void
    {
        $this->expectException(SchemaOutOfDateException::class);
        $this->expectExceptionMessage(
            'The database is not up to date with the application: table nothing_here is missing'
        );

        $this->db->fetchAll('SELECT * FROM nothing_here');
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        $rows = $this->db->fetchAll(
            'SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?
             ORDER BY ordinal_position',
            [$table]
        );

        return array_map(static fn (array $row): string => Row::string($row, 'column_name'), $rows);
    }

    /**
     * Makes the database look like one that has never been migrated. The tables
     * are untouched - only the bookkeeping goes, which is exactly the state a
     * stack that predates this mechanism is in.
     */
    private function forgetMigrations(): void
    {
        $this->db->execute('DELETE FROM schema_migration');
    }
}
