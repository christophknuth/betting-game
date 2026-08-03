<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use RuntimeException;

/**
 * One file from database/migrations/, named `0003_participant_status.sql`.
 *
 * The version is what is recorded as applied, so it has to be stable: renaming
 * the descriptive half is free, changing the number in front of it makes the
 * database believe it has never run.
 */
final class Migration
{
    private function __construct(
        public readonly string $version,
        public readonly string $name,
        public readonly string $path
    ) {
    }

    public static function fromPath(string $path): self
    {
        $file = basename($path, '.sql');

        if (preg_match('/^(\d{4})_([a-z0-9_]+)$/', $file, $matches) !== 1) {
            throw new RuntimeException(
                "Migration $file is not named NNNN_lower_case_words.sql, so its order is not defined"
            );
        }

        return new self($matches[1], $matches[2], $path);
    }

    /**
     * The statements in the file, in order.
     *
     * Split rather than handed to the driver in one piece: a migration that
     * fails should name the statement it failed on, and DDL commits implicitly
     * anyway, so there is no transaction to keep them together in. A `;` ends a
     * statement only at the end of a line - which is why migrations are written
     * one statement per line group and never `SELECT ';'`.
     *
     * @return list<string>
     */
    public function statements(): array
    {
        $sql = file_get_contents($this->path);

        if ($sql === false) {
            throw new RuntimeException("Migration $this->version cannot be read: $this->path");
        }

        $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*$/m', $withoutComments);

        if ($parts === false) {
            throw new RuntimeException("Migration $this->version cannot be split into statements");
        }

        $statements = [];

        foreach ($parts as $part) {
            $statement = trim($part);

            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
