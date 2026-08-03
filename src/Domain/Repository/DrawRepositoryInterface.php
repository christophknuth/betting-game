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

    /**
     * B-28: drops the evaluation of a draw, before it is worked out again.
     *
     * A corrected draw may have moved onto another ticket, whose rows are not
     * the ones written before - recomputing would leave those behind rather
     * than replace them.
     */
    public function clearRowMatches(int $drawId): void;

    /**
     * The best result any row achieved in a draw, for the summary in B-05.
     *
     * @return array{matchedNumbers: int, superzahlMatched: bool}|null
     */
    public function bestMatchOf(int $drawId): ?array;

    /**
     * The winning classes a draw produced, aggregated over the ticket's rows.
     *
     * @return list<array{winningClass: int, rowCount: int, amount: float}>
     */
    public function winningClassesOf(int $drawId): array;

    /**
     * B-24: every row the ticket carried into this draw, with what it achieved.
     *
     * Driven from the ticket's rows rather than from the matches, so a row that
     * hit nothing is in the list too - and so is one the evaluation has not
     * reached yet, with its result left null.
     *
     * @return list<array{ticketRowId: int, participantId: int, displayName: string,
     *     numbers: list<int>, matchedNumbers: int|null, superzahlMatched: bool,
     *     winningClass: int|null, amount: float}>
     */
    public function rowResultsOf(int $drawId, int $ticketId): array;
}
