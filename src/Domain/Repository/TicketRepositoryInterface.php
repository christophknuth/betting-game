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
}
