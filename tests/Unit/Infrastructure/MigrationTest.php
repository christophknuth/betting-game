<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Infrastructure;

use BettingGame\Infrastructure\Persistence\Migration;
use BettingGame\Infrastructure\Persistence\Migrator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The migration files themselves, without a database.
 *
 * A migration's number is its identity: it is what `schema_migration` records,
 * so a file that is not named for one has no defined place in the order, and
 * two files with the same number would leave one of them silently unapplied.
 */
final class MigrationTest extends TestCase
{
    public function testTheNumberInFrontIsTheVersion(): void
    {
        $migration = Migration::fromPath('/app/database/migrations/0003_participant_status.sql');

        self::assertSame('0003', $migration->version);
        self::assertSame('participant_status', $migration->name);
    }

    public function testAFileWithoutANumberIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not named NNNN_lower_case_words.sql');

        Migration::fromPath('/app/database/migrations/add_status.sql');
    }

    public function testStatementsAreSplitAndCommentsDropped(): void
    {
        $file = sys_get_temp_dir() . '/0009_two_statements.sql';

        file_put_contents($file, <<<'SQL'
            -- A comment, and a blank line

            ALTER TABLE ticket
                ADD COLUMN IF NOT EXISTS duration_weeks TINYINT UNSIGNED NULL;

            ALTER TABLE ticket ADD COLUMN IF NOT EXISTS draw_days ENUM('a', 'b') NULL;
            SQL);

        $statements = Migration::fromPath($file)->statements();

        unlink($file);

        self::assertCount(2, $statements);
        self::assertStringStartsWith('ALTER TABLE ticket', $statements[0]);
        self::assertStringContainsString('draw_days', $statements[1]);
        self::assertStringNotContainsString('--', $statements[0]);
    }

    /**
     * Every file in database/migrations/ is one this runner can read. Cheap to
     * check and the failure it prevents is the expensive kind: a version switch
     * that stops halfway because a file was named by hand.
     */
    public function testEveryMigrationInTheRepositoryIsReadable(): void
    {
        $versions = [];

        foreach (Migrator::discover(Migrator::defaultDirectory()) as $migration) {
            self::assertNotEmpty(
                $migration->statements(),
                "$migration->version has no statements at all"
            );

            $versions[] = $migration->version;
        }

        self::assertNotEmpty($versions, 'the directory holds no migrations');
        self::assertSame(array_unique($versions), $versions, 'two migrations share a number');
    }
}
