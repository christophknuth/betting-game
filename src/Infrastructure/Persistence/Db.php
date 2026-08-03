<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Support\SchemaOutOfDateException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Thin typed wrapper around PDO.
 *
 * PDO::prepare() is declared as `PDOStatement|false` and fetch() as `mixed`,
 * so every call site would otherwise have to re-check both. The connection runs
 * with ERRMODE_EXCEPTION, which means a false return is already impossible -
 * this class turns that guarantee into something the type system can see.
 */
final class Db
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        /** @var array<string, mixed> $row */
        return $row;
    }

    /**
     * @param array<int|string, mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $rows = $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): void
    {
        $this->run($sql, $params);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function run(string $sql, array $params): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);

            if ($stmt === false) {
                throw new RuntimeException('Failed to prepare statement: ' . $sql);
            }

            $stmt->execute($params);

            return $stmt;
        } catch (PDOException $e) {
            throw self::schemaFault($e) ?? $e;
        }
    }

    /**
     * The two driver errors that mean "your database is older than your code".
     *
     * They arrive as `SQLSTATE[42S22]: Column not found: 1054 Unknown column
     * 't.duration_weeks' in 'SELECT'` - a sentence that names a table alias and
     * an SQL clause, is only ever in English, and reached a participant's
     * screen once. Everything else stays a `PDOException` and is a 500 with no
     * details, because an unrecognised database error can carry the query.
     *
     * The original is kept as the previous exception, so the log still has the
     * statement that failed.
     */
    private static function schemaFault(PDOException $e): ?SchemaOutOfDateException
    {
        $message = $e->getMessage();

        if ($e->getCode() === '42S22' && preg_match("/Unknown column '([^']+)'/", $message, $m) === 1) {
            // Qualified as `alias.column` in a join; the column is the half a
            // migration can add.
            return SchemaOutOfDateException::missingColumn(self::unqualified($m[1]), $e);
        }

        if ($e->getCode() === '42S02' && preg_match("/Table '([^']+)' doesn't exist/", $message, $m) === 1) {
            return SchemaOutOfDateException::missingTable(self::unqualified($m[1]), $e);
        }

        return null;
    }

    /**
     * `t.duration_weeks` and `betting_game.ticket` name the same things as
     * `duration_weeks` and `ticket`, and only the short half is what a
     * migration adds.
     */
    private static function unqualified(string $name): string
    {
        $separator = strrpos($name, '.');

        return $separator === false ? $name : substr($name, $separator + 1);
    }
}
