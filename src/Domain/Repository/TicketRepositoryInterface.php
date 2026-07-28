<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Model\Ticket;
use DateTimeImmutable;

interface TicketRepositoryInterface
{
    public function find(int $id): ?Ticket;

    public function save(Ticket $ticket): void;

    public function nextIdentity(): int;

    public function findByPeriodStart(int $tippYearId, DateTimeImmutable $periodStart): ?Ticket;

    /**
     * The ticket a draw belongs to: the one whose period contains the draw date.
     */
    public function findCovering(int $tippYearId, DateTimeImmutable $date): ?Ticket;

    /** @return list<array<string, mixed>> */
    public function findByTippYear(int $tippYearId): array;

    /**
     * B-02: the tickets a participant's row was printed on, per tipp year.
     *
     * @return list<array<string, mixed>>
     */
    public function findWithParticipation(int $tippYearId, int $participantId): array;

    /**
     * Maps the ticket's bet rows to their snapshot ids.
     *
     * Evaluating a draw works on the aggregate, which knows bet row ids, but
     * the per-row result is stored against the snapshot - the snapshot is what
     * actually took part in the draw.
     *
     * @return array<int, int> bet row id => ticket row id
     */
    public function rowIdsOf(int $ticketId): array;
}
