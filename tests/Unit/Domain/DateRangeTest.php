<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Exception\InvalidArgumentException;
use BettingGame\Domain\ValueObject\DateRange;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
    public function testEndMustNotBeBeforeStart(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DateRange::fromStrings('2026-03-31', '2026-01-01');
    }

    public function testASingleDayIsAValidRange(): void
    {
        $range = DateRange::fromStrings('2026-01-01', '2026-01-01');

        self::assertSame(1, $range->dayCount());
    }

    public function testTheTimeOfDayIsIgnored(): void
    {
        $range = new DateRange(
            new DateTimeImmutable('2026-01-01 23:59:59'),
            new DateTimeImmutable('2026-01-31 00:00:01')
        );

        self::assertTrue($range->contains(new DateTimeImmutable('2026-01-01 00:00:00')));
        self::assertTrue($range->contains(new DateTimeImmutable('2026-01-31 23:59:59')));
    }

    public function testBothEndsBelongToTheRange(): void
    {
        $range = DateRange::fromStrings('2026-01-01', '2026-03-31');

        self::assertTrue($range->contains(new DateTimeImmutable('2026-01-01')));
        self::assertTrue($range->contains(new DateTimeImmutable('2026-03-31')));
        self::assertFalse($range->contains(new DateTimeImmutable('2025-12-31')));
        self::assertFalse($range->contains(new DateTimeImmutable('2026-04-01')));
    }

    public function testOverlapIsSymmetric(): void
    {
        $a = DateRange::fromStrings('2026-01-01', '2026-03-31');
        $b = DateRange::fromStrings('2026-03-15', '2026-05-15');

        self::assertTrue($a->overlaps($b));
        self::assertTrue($b->overlaps($a));
    }

    public function testTouchingOnASingleDayCountsAsOverlap(): void
    {
        $a = DateRange::fromStrings('2026-01-01', '2026-03-31');
        $b = DateRange::fromStrings('2026-03-31', '2026-06-30');

        self::assertTrue($a->overlaps($b), 'both ranges include 2026-03-31');
    }

    public function testAdjacentRangesDoNotOverlap(): void
    {
        $a = DateRange::fromStrings('2026-01-01', '2026-03-31');
        $b = DateRange::fromStrings('2026-04-01', '2026-06-30');

        self::assertFalse($a->overlaps($b));
    }

    public function testCoversIsNotSymmetric(): void
    {
        $year = DateRange::fromStrings('2026-01-01', '2026-12-31');
        $quarter = DateRange::fromStrings('2026-01-01', '2026-03-31');

        self::assertTrue($year->covers($quarter));
        self::assertFalse($quarter->covers($year));
    }

    public function testARangeCoversItself(): void
    {
        $range = DateRange::fromStrings('2026-01-01', '2026-12-31');

        self::assertTrue($range->covers($range));
    }

    public function testDayCountIncludesBothEnds(): void
    {
        self::assertSame(31, DateRange::fromStrings('2026-01-01', '2026-01-31')->dayCount());
        self::assertSame(365, DateRange::fromStrings('2026-01-01', '2026-12-31')->dayCount());
    }

    public function testEquality(): void
    {
        self::assertTrue(
            DateRange::fromStrings('2026-01-01', '2026-12-31')
                ->equals(DateRange::fromStrings('2026-01-01', '2026-12-31'))
        );
        self::assertFalse(
            DateRange::fromStrings('2026-01-01', '2026-12-31')
                ->equals(DateRange::fromStrings('2026-01-01', '2026-06-30'))
        );
    }

    public function testStringRepresentation(): void
    {
        self::assertSame(
            '2026-01-01 .. 2026-12-31',
            (string) DateRange::fromStrings('2026-01-01', '2026-12-31')
        );
    }
}
