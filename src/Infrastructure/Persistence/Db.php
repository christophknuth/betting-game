<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use PDO;
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
        $stmt = $this->pdo->prepare($sql);

        if ($stmt === false) {
            throw new RuntimeException('Failed to prepare statement: ' . $sql);
        }

        $stmt->execute($params);

        return $stmt;
    }
}
