<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Model\BetPeriod;
use BettingGame\Domain\ValueObject\DateRange;
use DateTimeImmutable;

interface BetPeriodRepositoryInterface
{
    public function find(int $id): ?BetPeriod;

    public function save(BetPeriod $period): void;

    public function nextIdentity(): int;

    /** @return list<BetPeriod> */
    public function findByTippYear(int $tippYearId): array;

    /**
     * The period covering a given day - the one whose rows go on a ticket
     * starting that day. Null when the administrator left a gap.
     */
    public function findActiveOn(int $tippYearId, DateTimeImmutable $date): ?BetPeriod;

    /**
     * The ranges a new period has to be checked against.
     *
     * Overlap cannot be expressed as a unique key, so the rule lives in
     * BetPeriod::assertNoOverlap() and needs the existing ranges to check.
     *
     * @return list<DateRange>
     */
    public function existingRanges(int $tippYearId, ?int $excludeId = null): array;

    public function nextSequence(int $tippYearId): int;
}
