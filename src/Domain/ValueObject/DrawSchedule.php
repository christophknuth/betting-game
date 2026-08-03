<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use DateInterval;
use DateTimeImmutable;

/**
 * How long a Spielauftrag runs, and which of the two weekly draws it plays.
 *
 * A ticket is not handed in for a period somebody types an end date for. It is
 * handed in on one day, for a freely chosen number of weeks, and for Wednesday,
 * Saturday or both - that is what the form at the counter asks for, so it is
 * what gets recorded. The period and the number of draws follow from it and are
 * never entered by hand.
 *
 * 6 aus 49 is drawn on Wednesday and on Saturday, holidays included, and there
 * is no other draw day. That is what makes the derivation exact rather than an
 * estimate, and it is why nothing here consults a calendar of holidays.
 */
final class DrawSchedule
{
    public const WEDNESDAY = 'wednesday';
    public const SATURDAY = 'saturday';
    public const BOTH = 'both';

    /**
     * The draw days per choice, as ISO weekday numbers - what
     * `DateTimeImmutable::format('N')` returns, Monday being 1.
     *
     * @var array<string, list<int>>
     */
    private const WEEKDAYS = [
        self::WEDNESDAY => [3],
        self::SATURDAY => [6],
        self::BOTH => [3, 6],
    ];

    private const DAYS_PER_WEEK = 7;

    /**
     * The longest Laufzeit that can be handed in.
     *
     * A guard against a mistyped duration rather than a rule of the domain: a
     * ticket over a thousand weeks would bill every member for a thousand
     * weeks, and the fees are written the moment the ticket is.
     */
    private const MAX_WEEKS = 52;

    public function __construct(
        private int $durationWeeks,
        private string $drawDays
    ) {
        if ($durationWeeks < 1) {
            throw new BusinessRuleViolationException('A ticket runs for at least one week');
        }

        if ($durationWeeks > self::MAX_WEEKS) {
            throw new BusinessRuleViolationException(
                sprintf('A ticket runs for at most %d weeks', self::MAX_WEEKS)
            );
        }

        if (!isset(self::WEEKDAYS[$drawDays])) {
            throw new BusinessRuleViolationException(sprintf(
                'Draw days must be one of %s, got %s',
                implode(', ', array_keys(self::WEEKDAYS)),
                $drawDays
            ));
        }
    }

    /**
     * The last day the Spielauftrag plays, both ends included.
     *
     * A Laufzeit of n weeks covers n × 7 days counting the day of submission,
     * so the last one is the day before the order would start over.
     */
    public function periodEnd(DateTimeImmutable $periodStart): DateTimeImmutable
    {
        $days = $this->durationWeeks * self::DAYS_PER_WEEK - 1;

        return $periodStart->add(new DateInterval("P{$days}D"));
    }

    /**
     * How many draws the ticket takes part in.
     *
     * No walk over the calendar is needed, and the start date does not matter:
     * the period is a whole number of weeks, and each weekday falls exactly
     * once into every one of them. Handing in on a Wednesday therefore plays
     * that same evening's draw - which is what the counter does too, right up
     * to the Annahmeschluss.
     */
    public function drawCount(): int
    {
        return $this->durationWeeks * count(self::WEEKDAYS[$this->drawDays]);
    }

    public function durationWeeks(): int
    {
        return $this->durationWeeks;
    }

    public function drawDays(): string
    {
        return $this->drawDays;
    }
}
