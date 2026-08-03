<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Support\Row;
use BettingGame\Domain\Model\Ticket;
use BettingGame\Domain\Repository\TicketRepositoryInterface;
use BettingGame\Domain\ValueObject\DrawSchedule;
use BettingGame\Domain\ValueObject\LottoNumbers;
use BettingGame\Domain\ValueObject\Superzahl;
use DateTimeImmutable;
use BettingGame\Infrastructure\Projection\TicketProjector;

/**
 * The shared monthly ticket and the row snapshots on it.
 *
 * `ticket_row.numbers` is a copy, not a reference: correcting a bet row later
 * must not change what was handed in. The participant behind a row is not
 * copied - that never changes - so reading a ticket joins bet_row for it.
 */
final class TicketRepository extends EventSourcedRepository implements TicketRepositoryInterface
{
    private const STREAM_PREFIX = 'ticket-';

    /** The read model this repository keeps current; see EventSourcedRepository. */
    protected function projectionName(): string
    {
        return TicketProjector::NAME;
    }

    public function find(int $id): ?Ticket
    {
        $row = $this->db->fetchOne('SELECT * FROM ticket WHERE ticket_id = ?', [$id]);

        return $row === null ? null : $this->toAggregate($row);
    }

    public function save(Ticket $ticket): void
    {
        $exists = $ticket->isPersisted();

        $version = $this->transactionally(function () use ($ticket, $exists): int {
            $version = $this->append(
                self::STREAM_PREFIX . $ticket->id(),
                $ticket->releaseEvents(),
                $ticket->originalVersion()
            );

            if ($exists) {
                $this->db->execute(
                    '
                    UPDATE ticket
                    SET lottery_reference = ?, superzahl = ?, row_count = ?, draw_count = ?,
                        processing_fee = ?, total_cost = ?, status = ?, submitted_at = ?, version = ?
                    WHERE ticket_id = ?
                    ',
                    [
                        $ticket->lotteryReference(),
                        $ticket->superzahl()?->value(),
                        $ticket->rowCount(),
                        $ticket->drawCount(),
                        $ticket->processingFee(),
                        $ticket->totalCost(),
                        $ticket->status(),
                        $ticket->submittedAt()?->format('Y-m-d H:i:s'),
                        $version,
                        $ticket->id(),
                    ]
                );
            } else {
                // uk_year_period must reject a second ticket for the same month
                $this->db->execute(
                    '
                    INSERT INTO ticket (
                        ticket_id, tipp_year_id, period_start, period_end, duration_weeks, draw_days,
                        lottery_reference, superzahl, row_count, draw_count, processing_fee,
                        total_cost, status, submitted_at, version
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ',
                    [
                        $ticket->id(),
                        $ticket->tippYearId(),
                        $ticket->periodStart()->format('Y-m-d'),
                        $ticket->periodEnd()->format('Y-m-d'),
                        $ticket->schedule()?->durationWeeks(),
                        $ticket->schedule()?->drawDays(),
                        $ticket->lotteryReference(),
                        $ticket->superzahl()?->value(),
                        $ticket->rowCount(),
                        $ticket->drawCount(),
                        $ticket->processingFee(),
                        $ticket->totalCost(),
                        $ticket->status(),
                        $ticket->submittedAt()?->format('Y-m-d H:i:s'),
                        $version,
                    ]
                );
            }

            // The rows are a projection of the ticket, keyed by (ticket, bet row):
            // upsert is right here, re-saving must not duplicate them.
            foreach ($ticket->rows() as $row) {
                $this->db->execute(
                    '
                    INSERT INTO ticket_row (ticket_id, bet_row_id, numbers)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE numbers = VALUES(numbers)
                    ',
                    [
                        $ticket->id(),
                        $row['betRowId'],
                        json_encode($row['numbers']->toArray(), JSON_THROW_ON_ERROR),
                    ]
                );
            }

            return $version;
        });

        $ticket->markCommitted($version);
    }

    public function nextIdentity(): int
    {
        return $this->nextId('ticket', 'ticket_id');
    }

    public function findByPeriodStart(int $tippYearId, DateTimeImmutable $periodStart): ?Ticket
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ticket WHERE tipp_year_id = ? AND period_start = ?',
            [$tippYearId, $periodStart->format('Y-m-d')]
        );

        return $row === null ? null : $this->toAggregate($row);
    }

    public function findCovering(int $tippYearId, DateTimeImmutable $date): ?Ticket
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM ticket WHERE tipp_year_id = ? AND ? BETWEEN period_start AND period_end',
            [$tippYearId, $date->format('Y-m-d')]
        );

        return $row === null ? null : $this->toAggregate($row);
    }

    /** @return list<array<string, mixed>> */
    public function findByTippYear(int $tippYearId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM ticket WHERE tipp_year_id = ? ORDER BY period_start DESC',
            [$tippYearId]
        );
    }

    /**
     * B-02: every ticket of the year plus whether this participant's row was on it.
     *
     * A left join, not a filter - joining a year mid-way is normal and the
     * earlier tickets should still show up, marked as not participated.
     *
     * @return list<array<string, mixed>>
     */
    public function findWithParticipation(int $tippYearId, int $participantId): array
    {
        return $this->db->fetchAll(
            '
            SELECT
                t.ticket_id, t.period_start, t.period_end, t.duration_weeks, t.draw_days,
                t.draw_count, t.row_count, t.processing_fee, t.total_cost, t.status,
                t.lottery_reference,
                own.ticket_row_id IS NOT NULL AS participated,
                own.numbers AS own_numbers
            FROM ticket t
            LEFT JOIN (
                SELECT tr.ticket_id, tr.ticket_row_id, tr.numbers
                FROM ticket_row tr
                JOIN bet_row br ON br.bet_row_id = tr.bet_row_id
                WHERE br.participant_id = ?
            ) own ON own.ticket_id = t.ticket_id
            WHERE t.tipp_year_id = ?
            ORDER BY t.period_start DESC
            ',
            [$participantId, $tippYearId]
        );
    }

    /** @return list<array{ticketRowId: int, numbers: LottoNumbers}> */
    public function snapshotRowsOf(int $ticketId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ticket_row_id, numbers FROM ticket_row WHERE ticket_id = ? ORDER BY ticket_row_id',
            [$ticketId]
        );

        return array_map(
            static fn (array $row): array => [
                'ticketRowId' => Row::int($row, 'ticket_row_id'),
                'numbers' => LottoNumbers::fromMixed(Row::json($row, 'numbers')),
            ],
            $rows
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toAggregate(array $row): Ticket
    {
        $ticketId = Row::int($row, 'ticket_id');
        $superzahl = Row::nullableInt($row, 'superzahl');

        // Both columns are written together or not at all, so one of them being
        // there is enough to rebuild the schedule - the check on the other is
        // what keeps the value object out of a half-filled row.
        $durationWeeks = Row::nullableInt($row, 'duration_weeks');
        $drawDays = Row::nullableString($row, 'draw_days');

        return Ticket::fromProjection(
            id: $ticketId,
            tippYearId: Row::int($row, 'tipp_year_id'),
            periodStart: new DateTimeImmutable(Row::string($row, 'period_start')),
            periodEnd: new DateTimeImmutable(Row::string($row, 'period_end')),
            drawCount: Row::int($row, 'draw_count'),
            schedule: $durationWeeks === null || $drawDays === null
                ? null
                : new DrawSchedule($durationWeeks, $drawDays),
            processingFee: Row::nullableFloat($row, 'processing_fee') ?? 0.0,
            totalCost: Row::float($row, 'total_cost'),
            rows: $this->loadRows($ticketId),
            superzahl: $superzahl === null ? null : new Superzahl($superzahl),
            lotteryReference: Row::nullableString($row, 'lottery_reference'),
            status: Row::string($row, 'status'),
            submittedAt: ($submitted = Row::nullableString($row, 'submitted_at')) === null
                ? null
                : new DateTimeImmutable($submitted),
            version: Row::int($row, 'version')
        );
    }

    /**
     * @return list<array{betRowId: int, participantId: int, numbers: LottoNumbers}>
     */
    private function loadRows(int $ticketId): array
    {
        $rows = $this->db->fetchAll(
            '
            SELECT tr.bet_row_id, tr.numbers, br.participant_id
            FROM ticket_row tr
            JOIN bet_row br ON br.bet_row_id = tr.bet_row_id
            WHERE tr.ticket_id = ?
            ORDER BY br.participant_id
            ',
            [$ticketId]
        );

        return array_map(
            static fn (array $row): array => [
                'betRowId' => Row::int($row, 'bet_row_id'),
                'participantId' => Row::int($row, 'participant_id'),
                'numbers' => LottoNumbers::fromMixed(Row::json($row, 'numbers')),
            ],
            $rows
        );
    }
}
