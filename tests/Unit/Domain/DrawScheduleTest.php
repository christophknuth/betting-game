<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\ValueObject\DrawSchedule;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * What a Laufzeit and a choice of draw days make of a submission date.
 *
 * These are the numbers nobody types in any more, so they are the ones worth
 * pinning down: get the period end wrong by a day and a draw falls out of the
 * ticket that was paid for.
 */
final class DrawScheduleTest extends TestCase
{
    public function testAWeekEndsSixDaysAfterItStarts(): void
    {
        // Both ends included: Monday to Sunday is one week, not eight days.
        $schedule = new DrawSchedule(1, DrawSchedule::BOTH);

        self::assertSame(
            '2026-03-07',
            $schedule->periodEnd(new DateTimeImmutable('2026-03-01'))->format('Y-m-d')
        );
    }

    public function testTheLaufzeitIsCountedInWholeWeeksFromTheDayOfSubmission(): void
    {
        $schedule = new DrawSchedule(6, DrawSchedule::SATURDAY);

        self::assertSame(
            '2026-04-11',
            $schedule->periodEnd(new DateTimeImmutable('2026-03-01'))->format('Y-m-d'),
            'six weeks are 42 days, the last of them the 41st after the start'
        );
    }

    public function testBothDrawDaysAreTwoDrawsAWeek(): void
    {
        self::assertSame(8, (new DrawSchedule(4, DrawSchedule::BOTH))->drawCount());
    }

    public function testOneDrawDayIsOneDrawAWeek(): void
    {
        self::assertSame(4, (new DrawSchedule(4, DrawSchedule::WEDNESDAY))->drawCount());
        self::assertSame(4, (new DrawSchedule(4, DrawSchedule::SATURDAY))->drawCount());
    }

    /**
     * The count is a multiplication rather than a walk over the calendar, and
     * this is what says the shortcut is allowed: whichever weekday a ticket is
     * handed in on, its period really does contain that many draw days.
     */
    public function testTheCountMatchesTheDrawDaysInThePeriod(): void
    {
        // A Sunday, the Wednesday of a draw, and the Saturday of one
        foreach (['2026-03-01', '2026-03-04', '2026-03-07'] as $start) {
            foreach ([DrawSchedule::WEDNESDAY, DrawSchedule::SATURDAY, DrawSchedule::BOTH] as $days) {
                $schedule = new DrawSchedule(3, $days);
                $periodStart = new DateTimeImmutable($start);

                self::assertSame(
                    $this->countDrawDays($periodStart, $schedule->periodEnd($periodStart), $days),
                    $schedule->drawCount(),
                    "{$days} from {$start}"
                );
            }
        }
    }

    /** The same question asked the slow way: one day at a time. */
    private function countDrawDays(DateTimeImmutable $start, DateTimeImmutable $end, string $drawDays): int
    {
        $weekdays = match ($drawDays) {
            DrawSchedule::WEDNESDAY => ['3'],
            DrawSchedule::SATURDAY => ['6'],
            default => ['3', '6'],
        };

        $count = 0;

        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            if (in_array($day->format('N'), $weekdays, true)) {
                $count++;
            }
        }

        return $count;
    }

    public function testALaufzeitBelowAWeekIsRefused(): void
    {
        $this->expectException(BusinessRuleViolationException::class);
        new DrawSchedule(0, DrawSchedule::BOTH);
    }

    public function testAnImplausiblyLongLaufzeitIsRefused(): void
    {
        // A mistyped 520 would bill every member for ten years in one go.
        $this->expectException(BusinessRuleViolationException::class);
        new DrawSchedule(520, DrawSchedule::BOTH);
    }

    public function testAnUnknownDrawDayIsRefused(): void
    {
        // 6 aus 49 is drawn on Wednesday and on Saturday, and on no other day.
        $this->expectException(BusinessRuleViolationException::class);
        new DrawSchedule(4, 'friday');
    }
}
