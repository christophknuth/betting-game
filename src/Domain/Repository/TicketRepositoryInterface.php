<?php

declare(strict_types=1);

namespace BettingGame\Domain\Repository;

use BettingGame\Domain\Model\Ticket;
use BettingGame\Domain\ValueObject\LottoNumbers;
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
     * The rows as they were printed on the ticket, in the order they are stored.
     *
     * Evaluating a draw works on these rather than on the aggregate's rows: the
     * per-row result is keyed by the snapshot, because the snapshot is what
     * actually took part in the draw. The order is fixed here rather than left
     * to the caller because WinningsDistribution puts the remainder cent on the
     * first winning row - two callers ordering differently would attribute
     * different amounts to the same draw.
     *
     * @return list<array{ticketRowId: int, numbers: LottoNumbers}>
     */
    public function snapshotRowsOf(int $ticketId): array;
}
