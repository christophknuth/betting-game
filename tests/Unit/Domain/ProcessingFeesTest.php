<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\ValueObject\ProcessingFees;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The Bearbeitungsentgelt the lottery company charges per Spielauftrag.
 *
 * The rate depends on how long the order runs, and the boundary is the whole
 * point: a monthly ticket has to be billed at the multi-week rate and a single
 * week at the cheaper one. Getting the edge wrong is not a rounding error, it
 * is charging the syndicate the wrong price on every ticket.
 */
final class ProcessingFeesTest extends TestCase
{
    private const SINGLE = 0.60;
    private const MULTI = 1.00;

    public function testAWeekLongOrderIsBilledAtTheSingleWeekRate(): void
    {
        // Monday to Sunday, both ends included, is seven days.
        self::assertSame(self::SINGLE, $this->feeFor('2027-01-04', '2027-01-10'));
    }

    public function testTheDayAfterAWeekIsAlreadyMultiWeek(): void
    {
        self::assertSame(self::MULTI, $this->feeFor('2027-01-04', '2027-01-11'));
    }

    public function testASingleDayOrderIsSingleWeek(): void
    {
        self::assertSame(self::SINGLE, $this->feeFor('2027-01-04', '2027-01-04'));
    }

    public function testAMonthlyTicketIsMultiWeek(): void
    {
        // The case that actually occurs here: the syndicate submits one ticket
        // a month, so this is the rate it pays in practice.
        self::assertSame(self::MULTI, $this->feeFor('2027-01-01', '2027-01-31'));
    }

    public function testAShortFebruaryIsStillMultiWeek(): void
    {
        self::assertSame(self::MULTI, $this->feeFor('2027-02-01', '2027-02-28'));
    }

    public function testTheRatesAreReadableForDisplay(): void
    {
        $fees = new ProcessingFees(self::SINGLE, self::MULTI);

        self::assertSame(self::SINGLE, $fees->singleWeek());
        self::assertSame(self::MULTI, $fees->multiWeek());
    }

    public function testNoFeeIsAValidPriceList(): void
    {
        // A syndicate that is not charged one, and every tipp year created
        // before the rates existed.
        $fees = ProcessingFees::none();

        self::assertSame(0.0, $fees->forPeriod(
            new DateTimeImmutable('2027-01-01'),
            new DateTimeImmutable('2027-01-31')
        ));
    }

    public function testANegativeFeeIsRejected(): void
    {
        $this->expectException(BusinessRuleViolationException::class);

        new ProcessingFees(-0.10, 1.00);
    }

    private function feeFor(string $start, string $end): float
    {
        return (new ProcessingFees(self::SINGLE, self::MULTI))->forPeriod(
            new DateTimeImmutable($start),
            new DateTimeImmutable($end)
        );
    }
}
