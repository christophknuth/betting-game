<?php

declare(strict_types=1);

namespace BettingGame\Domain\ValueObject;

use BettingGame\Domain\Exception\BusinessRuleViolationException;
use DateTimeImmutable;

/**
 * What the lottery company charges per Spielauftrag, on top of the rows.
 *
 * The cost of a ticket is not just rows x draws x price. Every Spielauftrag
 * also carries a Bearbeitungsentgelt, and its amount depends on how long the
 * order runs: a one-week order is cheaper than a multi-week one. The two rates
 * belong to the tipp year, because that is where the price list that was
 * agreed for the season lives.
 *
 * The rule that picks between them sits here rather than in the ticket: the
 * ticket should not have to know how a price list is read, and keeping the
 * rates and the rule together means a change to either is one edit.
 */
final class ProcessingFees
{
    /**
     * A Spielauftrag counts as single-week while it covers at most this many
     * days, both ends included.
     *
     * Seven, because that is what a week is - not "two draws". The number of
     * draws is a property of the ticket that can vary with holidays, while the
     * price list talks about weeks.
     */
    private const SINGLE_WEEK_DAYS = 7;

    public function __construct(
        private float $singleWeek,
        private float $multiWeek
    ) {
        if ($singleWeek < 0.0 || $multiWeek < 0.0) {
            throw new BusinessRuleViolationException('A processing fee cannot be negative');
        }
    }

    /** No fee at all - what a tipp year created before the rates existed carries. */
    public static function none(): self
    {
        return new self(0.0, 0.0);
    }

    /**
     * The fee for a Spielauftrag covering this period, both ends included.
     */
    public function forPeriod(DateTimeImmutable $start, DateTimeImmutable $end): float
    {
        return $this->coversSingleWeek($start, $end) ? $this->singleWeek : $this->multiWeek;
    }

    public function coversSingleWeek(DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $days = (int) $start->diff($end)->days + 1;

        return $days <= self::SINGLE_WEEK_DAYS;
    }

    public function singleWeek(): float
    {
        return $this->singleWeek;
    }

    public function multiWeek(): float
    {
        return $this->multiWeek;
    }
}
