<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Model\Draw;
use DateTimeImmutable;

interface DrawRepositoryInterface
{
    public function find(int $id): ?Draw;

    public function save(Draw $draw): void;

    public function nextIdentity(): int;

    public function findByDate(DateTimeImmutable $drawDate): ?Draw;

    /** @return list<Draw> */
    public function findByTippYear(int $tippYearId): array;

    /**
     * B-05: the draws of a tipp year with what the ticket won in each of them.
     *
     * @return list<array<string, mixed>>
     */
    public function findWithWinnings(int $tippYearId): array;

    /**
     * B-13: the sum of everything the year's tickets won - the amount to distribute.
     */
    public function totalWinnings(int $tippYearId): float;

    /**
     * B-09: the per-row evaluation of a draw.
     *
     * @param list<array{ticketRowId: int, matchedNumbers: int, superzahlMatched: bool,
     *     winningClass: int|null, amount: float}> $matches
     */
    public function saveRowMatches(int $drawId, array $matches): void;
}
