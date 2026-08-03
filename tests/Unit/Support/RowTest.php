<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Support;

use BettingGame\Support\Row;
use BettingGame\Support\SchemaOutOfDateException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Typed access to a result row, and what it says when there is nothing to type.
 *
 * The interesting half is the distinction between a column that is absent and
 * one that is `NULL`. They used to be the same sentence, and the absent case is
 * what a `SELECT *` produces against a table the migrations have not reached -
 * the one of the two somebody can act on.
 */
final class RowTest extends TestCase
{
    public function testAColumnIsNarrowedToItsType(): void
    {
        $row = ['participant_id' => '7', 'display_name' => 'Anna', 'total' => '33.40'];

        self::assertSame(7, Row::int($row, 'participant_id'));
        self::assertSame('Anna', Row::string($row, 'display_name'));
        self::assertSame(33.40, Row::float($row, 'total'));
    }

    public function testMysqlsStringBooleansAreBooleans(): void
    {
        self::assertTrue(Row::bool(['is_active' => '1'], 'is_active'));
        self::assertFalse(Row::bool(['is_active' => '0'], 'is_active'));
    }

    public function testAColumnThatIsNotThereMeansTheDatabaseIsBehindTheCode(): void
    {
        $this->expectException(SchemaOutOfDateException::class);
        $this->expectExceptionMessage(
            'The stored data is not up to date with the application: status is missing'
        );

        // What `SELECT * FROM participant` hands back before 0003 has run
        Row::string(['participant_id' => 1, 'display_name' => 'Anna'], 'status');
    }

    public function testAColumnThatIsThereAndNullIsOurBugAndSaysSoDifferently(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Column superzahl is null');

        // The schema is right, the caller should have asked with nullableInt()
        Row::int(['superzahl' => null], 'superzahl');
    }

    public function testTheNullableAccessorsAnswerNullForBoth(): void
    {
        // Absent and NULL are the same thing to a caller that allows for it
        self::assertNull(Row::nullableInt(['superzahl' => null], 'superzahl'));
        self::assertNull(Row::nullableInt([], 'superzahl'));
        self::assertNull(Row::nullableString([], 'draw_days'));
        self::assertNull(Row::nullableFloat([], 'amount'));
    }

    public function testAValueOfTheWrongTypeIsNamedAsSuch(): void
    {
        $this->expectExceptionMessage('Column participant_id is not an integer');

        Row::int(['participant_id' => 'seven'], 'participant_id');
    }

    public function testAJsonColumnComesBackDecodedAndAnAbsentOneIsEmpty(): void
    {
        self::assertSame(['a' => 1], Row::json(['payload' => '{"a":1}'], 'payload'));
        self::assertSame([], Row::json(['payload' => null], 'payload'));
    }
}
