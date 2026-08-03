<?php

declare(strict_types=1);

namespace BettingGame\Support;

use RuntimeException;
use Throwable;

/**
 * The database is older than the code that reads it.
 *
 * A column the application selects but the table does not have, a table that
 * is missing entirely, a field an event payload was written without: three
 * ways of saying the same thing - what is stored predates this version. It is
 * the one server fault an operator can act on without reading a stack trace,
 * so it says so in words instead of arriving as `SQLSTATE[42S22]: Column not
 * found: 1054 Unknown column 't.duration_weeks' in 'SELECT'`.
 *
 * Deliberately not a `DomainException`: no rule of this domain was broken. It
 * lives in Support because all three throwers - `Row`, `Db` and through them
 * every repository - are on different layers, and the mapping to a status code
 * happens in exactly one place either way (`ErrorMapper`).
 *
 * The cure is `bin/migrate`, see database/migrations/README.md.
 */
final class SchemaOutOfDateException extends RuntimeException
{
    public static function missingColumn(string $column, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('The database is not up to date with the application: column %s is missing', $column),
            0,
            $previous
        );
    }

    public static function missingTable(string $table, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('The database is not up to date with the application: table %s is missing', $table),
            0,
            $previous
        );
    }

    /**
     * A key that is simply not there in a row or an event payload.
     *
     * Worded without "column" on purpose: `Row` reads decoded event payloads as
     * well as result rows, and an event written before a field existed is the
     * same situation one storey down.
     */
    public static function missingField(string $field): self
    {
        return new self(
            sprintf('The stored data is not up to date with the application: %s is missing', $field)
        );
    }
}
