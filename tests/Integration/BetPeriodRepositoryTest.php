<?php

declare(strict_types=1);

namespace BettingGame\Tests\Integration;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\Model\BetPeriod;
use BettingGame\Domain\ValueObject\DateRange;
use BettingGame\Infrastructure\Persistence\BetPeriodRepository;
use DateTimeImmutable;

final class BetPeriodRepositoryTest extends IntegrationTestCase
{
    private BetPeriodRepository $repository;
    private DateRange $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new BetPeriodRepository($this->db, $this->eventStore);
        $this->year = DateRange::fromStrings('2026-01-01', '2026-12-31');

        $this->db->execute(
            "
            INSERT INTO tipp_year (tipp_year_id, name, start_date, end_date, status, ticket_cost_per_row, version)
            VALUES (1, 'Tippjahr 2026', '2026-01-01', '2026-12-31', 'running', 1.20, 0)
            "
        );
    }

    private function givenPeriod(int $id, string $name, string $start, string $end, int $sequence = 1): BetPeriod
    {
        $period = BetPeriod::create(
            $id,
            1,
            $name,
            DateRange::fromStrings($start, $end),
            $this->year,
            $sequence
        );

        $this->repository->save($period);

        return $period;
    }

    public function testSavingRoundTrips(): void
    {
        $this->givenPeriod(1, 'Q1 2026', '2026-01-01', '2026-03-31');

        $loaded = $this->repository->find(1);

        self::assertNotNull($loaded);
        self::assertSame('Q1 2026', $loaded->name());
        self::assertSame(1, $loaded->tippYearId());
        self::assertTrue($loaded->range()->equals(DateRange::fromStrings('2026-01-01', '2026-03-31')));
        self::assertSame(1, $this->eventStore->getStreamVersion('bet_period-1'));
    }

    public function testASinglePeriodMaySpanTheWholeYear(): void
    {
        $this->givenPeriod(1, '2026 gesamt', '2026-01-01', '2026-12-31');

        $loaded = $this->repository->find(1);

        self::assertNotNull($loaded);
        self::assertSame(365, $loaded->range()->dayCount());
    }

    public function testFindActiveOnPicksThePeriodContainingTheDay(): void
    {
        $this->givenPeriod(1, 'Q1 2026', '2026-01-01', '2026-03-31', 1);
        $this->givenPeriod(2, 'Q2 2026', '2026-04-01', '2026-06-30', 2);

        $q1 = $this->repository->findActiveOn(1, new DateTimeImmutable('2026-03-31'));
        $q2 = $this->repository->findActiveOn(1, new DateTimeImmutable('2026-04-01'));

        self::assertNotNull($q1);
        self::assertNotNull($q2);
        self::assertSame('Q1 2026', $q1->name());
        self::assertSame('Q2 2026', $q2->name());
    }

    public function testAGapInThePeriodsReturnsNothing(): void
    {
        $this->givenPeriod(1, 'Q1 2026', '2026-01-01', '2026-03-31');
        $this->givenPeriod(2, 'Q3 2026', '2026-07-01', '2026-09-30', 2);

        self::assertNull($this->repository->findActiveOn(1, new DateTimeImmutable('2026-05-01')));
    }

    public function testExistingRangesFeedTheOverlapCheck(): void
    {
        $this->givenPeriod(1, 'Q1 2026', '2026-01-01', '2026-03-31');

        $existing = $this->repository->existingRanges(1);
        self::assertCount(1, $existing);

        // Adjacent is fine
        BetPeriod::assertNoOverlap(DateRange::fromStrings('2026-04-01', '2026-06-30'), $existing);

        $this->expectException(BusinessRuleViolationException::class);
        BetPeriod::assertNoOverlap(DateRange::fromStrings('2026-03-31', '2026-06-30'), $existing);
    }

    public function testExistingRangesCanExcludeThePeriodBeingEdited(): void
    {
        $this->givenPeriod(1, 'Q1 2026', '2026-01-01', '2026-03-31');

        self::assertCount(1, $this->repository->existingRanges(1));
        self::assertCount(0, $this->repository->existingRanges(1, 1));
    }

    public function testFindByTippYearIsOrderedByStart(): void
    {
        $this->givenPeriod(2, 'Q2 2026', '2026-04-01', '2026-06-30', 2);
        $this->givenPeriod(1, 'Q1 2026', '2026-01-01', '2026-03-31', 1);

        $periods = $this->repository->findByTippYear(1);

        self::assertCount(2, $periods);
        self::assertSame('Q1 2026', $periods[0]->name());
        self::assertSame('Q2 2026', $periods[1]->name());
    }

    public function testNextSequenceCountsUpPerTippYear(): void
    {
        self::assertSame(1, $this->repository->nextSequence(1));

        $this->givenPeriod(1, 'Q1 2026', '2026-01-01', '2026-03-31', 1);

        self::assertSame(2, $this->repository->nextSequence(1));
    }

    public function testTwoPeriodsCannotStartOnTheSameDay(): void
    {
        $this->givenPeriod(1, 'Q1 2026', '2026-01-01', '2026-03-31');

        $this->expectExceptionMessageMatches('/uk_year_start/');
        $this->givenPeriod(2, 'Kollision', '2026-01-01', '2026-02-28', 2);
    }
}
