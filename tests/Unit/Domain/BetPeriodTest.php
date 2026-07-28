<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Event\BetPeriodCreated;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Model\BetPeriod;
use BettingGame\Domain\ValueObject\DateRange;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class BetPeriodTest extends TestCase
{
    private function tippYear(): DateRange
    {
        return DateRange::fromStrings('2026-01-01', '2026-12-31');
    }

    public function testCreatingRecordsAnEvent(): void
    {
        $period = BetPeriod::create(
            1,
            5,
            'Q1 2026',
            DateRange::fromStrings('2026-01-01', '2026-03-31'),
            $this->tippYear()
        );

        $events = $period->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(BetPeriodCreated::class, $events[0]);
        self::assertSame('bet_period', $events[0]->aggregateType());
        self::assertSame('2026-03-31', $events[0]->toArray()['end_date']);
    }

    public function testAPeriodMaySpanTheWholeTippYear(): void
    {
        $period = BetPeriod::create(1, 5, '2026 gesamt', $this->tippYear(), $this->tippYear());

        self::assertTrue($period->range()->equals($this->tippYear()));
        self::assertSame(365, $period->range()->dayCount());
    }

    public function testAPeriodMustStayInsideItsTippYear(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        BetPeriod::create(
            1,
            5,
            'Reicht ins Folgejahr',
            DateRange::fromStrings('2026-11-01', '2027-01-31'),
            $this->tippYear()
        );
    }

    public function testAPeriodNeedsAName(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        BetPeriod::create(1, 5, '   ', $this->tippYear(), $this->tippYear());
    }

    public function testOverlappingPeriodsAreRejected(): void
    {
        $existing = [
            DateRange::fromStrings('2026-01-01', '2026-03-31'),
            DateRange::fromStrings('2026-04-01', '2026-06-30'),
        ];

        $this->expectException(BusinessRuleViolationException::class);
        BetPeriod::assertNoOverlap(DateRange::fromStrings('2026-03-15', '2026-05-15'), $existing);
    }

    public function testAdjacentPeriodsDoNotOverlap(): void
    {
        $existing = [DateRange::fromStrings('2026-01-01', '2026-03-31')];

        BetPeriod::assertNoOverlap(DateRange::fromStrings('2026-04-01', '2026-06-30'), $existing);

        $this->expectNotToPerformAssertions();
    }

    public function testTheActiveDayIsInsideTheRange(): void
    {
        $period = BetPeriod::create(
            1,
            5,
            'Q1 2026',
            DateRange::fromStrings('2026-01-01', '2026-03-31'),
            $this->tippYear()
        );

        self::assertTrue($period->isActiveOn(new DateTimeImmutable('2026-01-01')));
        self::assertTrue($period->isActiveOn(new DateTimeImmutable('2026-03-31')), 'the end day belongs to it');
        self::assertFalse($period->isActiveOn(new DateTimeImmutable('2026-04-01')));
    }

    public function testRehydrationCarriesTheVersion(): void
    {
        $period = BetPeriod::fromProjection(
            1,
            5,
            'Q1 2026',
            DateRange::fromStrings('2026-01-01', '2026-03-31'),
            1,
            3
        );

        self::assertSame(3, $period->version());
        self::assertSame(3, $period->originalVersion());
        self::assertCount(0, $period->releaseEvents());
    }
}
