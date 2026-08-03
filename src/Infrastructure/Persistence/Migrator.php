<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Support\Row;
use DateTimeImmutable;
use PDOException;
use RuntimeException;

/**
 * Brings an existing database up to the schema this version expects.
 *
 * `database/schema.sql` is only read into an *empty* data directory, so from
 * the second release onwards it changes nothing on a stack that is already
 * running: the tables stay as they were, and the first request that selects a
 * new column fails. That is precisely how "Unknown column 't.duration_weeks'"
 * came to be on a participant's screen.
 *
 * So every schema change is also a file in `database/migrations/`, applied in
 * the order of its number and written down in `schema_migration` once it has
 * run. Applying is a deliberate step - `bin/migrate`, part of a version switch
 * - and never something a request does on the side: a web server with four
 * workers would otherwise start four ALTERs on the same table.
 *
 * Migrations are written so that running them a second time changes nothing
 * (`ADD COLUMN IF NOT EXISTS` and friends). MariaDB commits DDL implicitly, so
 * a migration that fails halfway cannot be rolled back - being able to simply
 * run it again is what takes the place of a transaction.
 */
final class Migrator
{
    public const TABLE = 'schema_migration';

    public function __construct(
        private Db $db,
        private string $directory
    ) {
    }

    public static function defaultDirectory(): string
    {
        return dirname(__DIR__, 3) . '/database/migrations';
    }

    /**
     * Every migration file, oldest first.
     *
     * @return list<Migration>
     */
    public function all(): array
    {
        return self::discover($this->directory);
    }

    /**
     * The same, for whoever only wants to look at the files - reading a
     * directory needs no database.
     *
     * @return list<Migration>
     */
    public static function discover(string $directory): array
    {
        $paths = glob($directory . '/*.sql');

        if ($paths === false) {
            throw new RuntimeException("No migrations directory at $directory");
        }

        sort($paths, SORT_STRING);

        return array_map(Migration::fromPath(...), $paths);
    }

    /**
     * Those this database has not recorded, oldest first.
     *
     * @return list<Migration>
     */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->all(),
            static fn (Migration $migration): bool => !in_array($migration->version, $applied, true)
        ));
    }

    /**
     * Applies what is pending and returns it.
     *
     * @return list<Migration>
     */
    public function migrate(): array
    {
        $this->ensureTable();

        $applied = [];

        foreach ($this->pending() as $migration) {
            $this->apply($migration);
            $applied[] = $migration;
        }

        return $applied;
    }

    /**
     * The versions already in this database, or none at all where the table
     * does not exist yet - a database from before this mechanism.
     *
     * @return list<string>
     */
    public function applied(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $rows = $this->db->fetchAll('SELECT version FROM ' . self::TABLE . ' ORDER BY version');

        return array_map(static fn (array $row): string => Row::string($row, 'version'), $rows);
    }

    private function apply(Migration $migration): void
    {
        $number = 0;

        foreach ($migration->statements() as $statement) {
            $number++;

            try {
                // exec(), not prepare/execute: a migration statement takes no
                // parameters, and MySQL's prepared-statement protocol rejects
                // some of them outright - `PREPARE`, which is how MariaDB does
                // a conditional UPDATE, is one.
                $this->db->pdo()->exec($statement);
            } catch (PDOException $e) {
                throw new RuntimeException(
                    sprintf(
                        'Migration %s_%s failed at statement %d: %s',
                        $migration->version,
                        $migration->name,
                        $number,
                        $e->getMessage()
                    ),
                    0,
                    $e
                );
            }
        }

        $this->db->execute(
            'INSERT INTO ' . self::TABLE . ' (version, name, applied_at) VALUES (?, ?, ?)',
            [$migration->version, $migration->name, (new DateTimeImmutable())->format('Y-m-d H:i:s.u')]
        );
    }

    /**
     * The bookkeeping table itself, for a database that predates it.
     *
     * It is in `schema.sql` as well, for the same reason every other table is:
     * a fresh installation should not need a migration run to have a complete
     * schema. This is the other half - `CREATE TABLE IF NOT EXISTS`, so the two
     * paths meet.
     */
    private function ensureTable(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                version VARCHAR(20) PRIMARY KEY,
                name VARCHAR(191) NOT NULL,
                applied_at DATETIME(6) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function tableExists(): bool
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS found FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [self::TABLE]
        );

        return $row !== null && Row::int($row, 'found') > 0;
    }
}
