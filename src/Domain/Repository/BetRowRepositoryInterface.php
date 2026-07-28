<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Model\BetRow;
use DateTimeImmutable;

interface BetRowRepositoryInterface
{
    public function find(int $id): ?BetRow;

    public function save(BetRow $row): void;

    public function nextIdentity(): int;

    public function findByParticipantAndPeriod(int $participantId, int $betPeriodId): ?BetRow;

    /** @return list<BetRow> */
    public function findByPeriod(int $betPeriodId): array;

    /**
     * B-01: the participant's row valid on a given day.
     */
    public function findActiveRowOf(int $participantId, int $tippYearId, DateTimeImmutable $date): ?BetRow;

    /**
     * B-12: every row that goes on a ticket starting on the given day - the rows
     * of the active period, restricted to participants with an active membership.
     *
     * @return list<BetRow>
     */
    public function findRowsForTicket(int $tippYearId, DateTimeImmutable $periodStart): array;

    /**
     * On how many tickets this row has been printed so far.
     */
    public function ticketCountOf(int $betRowId): int;
}
