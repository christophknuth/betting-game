<?php

declare(strict_types=1);

namespace BettingGame\Infrastructure\Persistence;

use BettingGame\Support\Row;
use BettingGame\Domain\Model\BetRow;
use BettingGame\Domain\Repository\BetRowRepositoryInterface;
use BettingGame\Domain\ValueObject\LottoNumbers;
use DateTimeImmutable;
use BettingGame\Infrastructure\Projection\BetRowProjector;

/**
 * That a participant holds only one row per period is a unique key on the
 * table, not a check here - a second insert fails with a duplicate key and the
 * application layer turns that into a 409.
 */
final class BetRowRepository extends EventSourcedRepository implements BetRowRepositoryInterface
{
    private const STREAM_PREFIX = 'bet_row-';

    /** The read model this repository keeps current; see EventSourcedRepository. */
    protected function projectionName(): string
    {
        return BetRowProjector::NAME;
    }

    public function find(int $id): ?BetRow
    {
        $row = $this->db->fetchOne('SELECT * FROM bet_row WHERE bet_row_id = ?', [$id]);

        return $row === null ? null : $this->toAggregate($row);
    }

    public function save(BetRow $betRow): void
    {
        $exists = $betRow->isPersisted();

        $version = $this->transactionally(function () use ($betRow, $exists): int {
            $version = $this->append(
                self::STREAM_PREFIX . $betRow->id(),
                $betRow->releaseEvents(),
                $betRow->originalVersion()
            );

            $numbers = json_encode($betRow->numbers()->toArray(), JSON_THROW_ON_ERROR);

            if ($exists) {
                $this->db->execute(
                    'UPDATE bet_row SET numbers = ?, version = ? WHERE bet_row_id = ?',
                    [$numbers, $version, $betRow->id()]
                );
            } else {
                // A plain INSERT on purpose: uk_participant_period has to reject
                // a second row for the period. An upsert would quietly write the
                // new numbers onto the row that is already there.
                $this->db->execute(
                    '
                    INSERT INTO bet_row (bet_row_id, participant_id, bet_period_id, numbers, assigned_at, version)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ',
                    [
                        $betRow->id(),
                        $betRow->participantId(),
                        $betRow->betPeriodId(),
                        $numbers,
                        $betRow->assignedAt()->format('Y-m-d H:i:s'),
                        $version,
                    ]
                );
            }

            return $version;
        });

        $betRow->markCommitted($version);
    }

    public function nextIdentity(): int
    {
        return $this->nextId('bet_row', 'bet_row_id');
    }

    public function findByParticipantAndPeriod(int $participantId, int $betPeriodId): ?BetRow
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM bet_row WHERE participant_id = ? AND bet_period_id = ?',
            [$participantId, $betPeriodId]
        );

        return $row === null ? null : $this->toAggregate($row);
    }

    /** @return list<BetRow> */
    public function findByPeriod(int $betPeriodId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM bet_row WHERE bet_period_id = ? ORDER BY participant_id',
            [$betPeriodId]
        );

        return array_map(fn (array $row): BetRow => $this->toAggregate($row), $rows);
    }

    public function findActiveRowOf(int $participantId, int $tippYearId, DateTimeImmutable $date): ?BetRow
    {
        $row = $this->db->fetchOne(
            '
            SELECT br.*
            FROM bet_row br
            JOIN bet_period bp ON bp.bet_period_id = br.bet_period_id
            WHERE br.participant_id = ?
              AND bp.tipp_year_id = ?
              AND ? BETWEEN bp.start_date AND bp.end_date
            ',
            [$participantId, $tippYearId, $date->format('Y-m-d')]
        );

        return $row === null ? null : $this->toAggregate($row);
    }

    /**
     * The rows that go on a ticket starting on the given day.
     *
     * Two filters, and both matter: the period has to cover the ticket's first
     * day, and the participant has to be an active member. Someone who left
     * keeps their row in the table but must not end up on a new ticket.
     *
     * @return list<BetRow>
     */
    public function findRowsForTicket(int $tippYearId, DateTimeImmutable $periodStart): array
    {
        $rows = $this->db->fetchAll(
            "
            SELECT br.*
            FROM bet_row br
            JOIN bet_period bp ON bp.bet_period_id = br.bet_period_id
            JOIN membership m
                ON m.participant_id = br.participant_id
               AND m.tipp_year_id = bp.tipp_year_id
            WHERE bp.tipp_year_id = ?
              AND ? BETWEEN bp.start_date AND bp.end_date
              AND m.status = 'active'
            ORDER BY br.participant_id
            ",
            [$tippYearId, $periodStart->format('Y-m-d')]
        );

        return array_map(fn (array $row): BetRow => $this->toAggregate($row), $rows);
    }

    public function ticketCountOf(int $betRowId): int
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM ticket_row WHERE bet_row_id = ?',
            [$betRowId]
        );

        return $row === null ? 0 : Row::int($row, 'cnt');
    }

    /** @param array<string, mixed> $row */
    private function toAggregate(array $row): BetRow
    {
        return BetRow::fromProjection(
            id: Row::int($row, 'bet_row_id'),
            participantId: Row::int($row, 'participant_id'),
            betPeriodId: Row::int($row, 'bet_period_id'),
            numbers: LottoNumbers::fromMixed(Row::json($row, 'numbers')),
            assignedAt: new DateTimeImmutable(Row::string($row, 'assigned_at')),
            version: Row::int($row, 'version')
        );
    }
}
