<?php

declare(strict_types=1);

namespace BettingGame\Domain\Model;

use BettingGame\Domain\Event\BetPeriodCreated;
use BettingGame\Domain\Exception\BusinessRuleViolationException;
use BettingGame\Domain\ValueObject\DateRange;
use DateTimeImmutable;

/**
 * The stretch of time a bet row is valid for.
 *
 * The administrator picks the boundaries freely: one period spanning the whole
 * tipp year reproduces "one row per year", twelve monthly periods let a row be
 * changed every month. A participant holds exactly one row per period - that is
 * the unique key on bet_row, and it is what replaced the fixed yearly rule.
 *
 * Not to be confused with the period on a Ticket: that one is the month the
 * ticket was submitted for, and the two grids need not line up.
 */
final class BetPeriod
{
    use RecordsEvents;

    private function __construct(
        private int $id,
        private int $tippYearId,
        private string $name,
        private DateRange $range,
        private int $sequence
    ) {
    }

    /**
     * @param DateRange $tippYearRange the period has to stay inside its tipp year
     */
    public static function create(
        int $id,
        int $tippYearId,
        string $name,
        DateRange $range,
        DateRange $tippYearRange,
        int $sequence = 1
    ): self {
        if (trim($name) === '') {
            throw new BusinessRuleViolationException('A bet period needs a name');
        }

        if (!$tippYearRange->covers($range)) {
            throw new BusinessRuleViolationException(
                sprintf('The period %s is not inside the tipp year %s', $range, $tippYearRange)
            );
        }

        $period = new self($id, $tippYearId, trim($name), $range, $sequence);

        $period->recordEvent(new BetPeriodCreated(
            (string) $id,
            $tippYearId,
            trim($name),
            $range->start()->format('Y-m-d'),
            $range->end()->format('Y-m-d')
        ));

        return $period;
    }

    /**
     * Rehydrates from the read model without recording events.
     */
    public static function fromProjection(
        int $id,
        int $tippYearId,
        string $name,
        DateRange $range,
        int $sequence,
        int $version
    ): self {
        $period = new self($id, $tippYearId, $name, $range, $sequence);
        $period->markCommitted($version);

        return $period;
    }

    /**
     * Periods of one tipp year must not overlap - otherwise two rows of the
     * same participant would be valid on the same day and a ticket could not
     * tell which one to print.
     *
     * @param list<DateRange> $existing
     */
    public static function assertNoOverlap(DateRange $range, array $existing): void
    {
        foreach ($existing as $other) {
            if ($range->overlaps($other)) {
                throw new BusinessRuleViolationException(
                    sprintf('The period %s overlaps the existing period %s', $range, $other)
                );
            }
        }
    }

    public function isActiveOn(DateTimeImmutable $date): bool
    {
        return $this->range->contains($date);
    }

    public function id(): int
    {
        return $this->id;
    }

    public function tippYearId(): int
    {
        return $this->tippYearId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function range(): DateRange
    {
        return $this->range;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }
}
