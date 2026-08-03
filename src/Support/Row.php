<?php

declare(strict_types=1);

namespace BettingGame\Support;

use RuntimeException;

/**
 * Typed column access for result rows.
 *
 * A fetched row is `array<string, mixed>` - the driver does not know the column
 * types. These helpers narrow a column once, in one place, and fail loudly when
 * a column is missing or holds something unexpected, instead of casting silently.
 */
final class Row
{
    /** @param array<string, mixed> $row */
    public static function int(array $row, string $column): int
    {
        $value = self::require($row, $column);

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException("Column $column is not an integer");
    }

    /** @param array<string, mixed> $row */
    public static function nullableInt(array $row, string $column): ?int
    {
        return ($row[$column] ?? null) === null ? null : self::int($row, $column);
    }

    /** @param array<string, mixed> $row */
    public static function float(array $row, string $column): float
    {
        $value = self::require($row, $column);

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new RuntimeException("Column $column is not a number");
    }

    /** @param array<string, mixed> $row */
    public static function nullableFloat(array $row, string $column): ?float
    {
        return ($row[$column] ?? null) === null ? null : self::float($row, $column);
    }

    /** @param array<string, mixed> $row */
    public static function string(array $row, string $column): string
    {
        $value = self::require($row, $column);

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new RuntimeException("Column $column is not a string");
    }

    /** @param array<string, mixed> $row */
    public static function nullableString(array $row, string $column): ?string
    {
        return ($row[$column] ?? null) === null ? null : self::string($row, $column);
    }

    /** @param array<string, mixed> $row */
    public static function bool(array $row, string $column): bool
    {
        $value = self::require($row, $column);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        // MySQL hands BOOLEAN columns back as "0" / "1"
        if (is_string($value)) {
            return $value !== '' && $value !== '0';
        }

        throw new RuntimeException("Column $column is not a boolean");
    }

    /**
     * Decodes a JSON column.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function json(array $row, string $column): array
    {
        $value = $row[$column] ?? null;

        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        if (!is_string($value)) {
            throw new RuntimeException("Column $column is not a JSON string");
        }

        $decoded = json_decode($value, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Column $column does not contain a JSON object");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * A column that is absent and one that is `NULL` are two different faults,
     * and only one of them is anybody's to fix.
     *
     * `SELECT *` against a table that does not have the column yet hands back a
     * row without the key - the database is behind the code, and running the
     * migrations cures it. The same goes for an event payload written before
     * the field existed, which is why the sentence says "stored data" rather
     * than "column". A key that is there and `NULL` is the opposite: the schema
     * is right and the code asked for a non-null value where one is allowed to
     * be missing, which is a bug and needs `nullableX()`.
     *
     * @param array<string, mixed> $row
     */
    private static function require(array $row, string $column): mixed
    {
        if (!array_key_exists($column, $row)) {
            throw SchemaOutOfDateException::missingField($column);
        }

        if ($row[$column] === null) {
            throw new RuntimeException("Column $column is null");
        }

        return $row[$column];
    }
}
